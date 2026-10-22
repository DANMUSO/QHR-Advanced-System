<?php

namespace App\Modules\Organization\Services;

use App\Models\Company;
use App\Modules\Organization\Models\Backup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Exports every row a company owns to a gzip-compressed JSON file on the private local disk:
 * tables with a company_id column, plus child tables reached through foreign keys to rows already included
 * (e.g. claim items, pivots). Secrets (password hashes, 2FA secrets, integration credentials) are excluded.
 * restore() merges a file back by primary key (see the organization:restore-backup command).
 */
class BackupService
{
    private const SKIP_TABLES = [
        'org_backups', 'org_integrations', 'org_webhooks', 'sessions', 'personal_access_tokens',
        'password_reset_tokens', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'migrations',
    ];

    private const SAFE_USER_COLUMNS = ['id', 'company_id', 'name', 'email', 'is_active', 'activated_at', 'last_login_at', 'created_at', 'updated_at'];

    /** How many foreign-key hops below the company-owned tables to follow. */
    private const MAX_DEPTH = 3;

    public function create(Company $company, ?int $userId): Backup
    {
        $backup = new Backup([
            'user_id' => $userId,
            'filename' => 'backup-'.str($company->code ?: $company->id)->slug().'-'.now()->format('Ymd-His').'.json.gz',
            'status' => 'running',
        ]);
        $backup->company_id = $company->id;
        $backup->save();

        try {
            $data = ['meta' => ['company' => $company->only(['id', 'name', 'code']), 'generated_at' => now()->toIso8601String(), 'app' => config('app.name'), 'format' => 2], 'tables' => []];
            $data['tables']['companies'] = [$company->getAttributes()];
            foreach ($this->collect($company->id) as $table => $rows) {
                $data['tables'][$table] = $rows;
            }
            $records = collect($data['tables'])->sum(fn ($rows) => count($rows));

            $path = "organization/backups/{$company->id}/{$backup->filename}";
            Storage::disk('local')->put($path, gzencode(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 6));

            $backup->forceFill([
                'path' => $path, 'size' => Storage::disk('local')->size($path), 'status' => 'completed',
                'tables' => count($data['tables']), 'records' => $records,
            ])->save();
        } catch (Throwable $e) {
            report($e);
            $backup->forceFill(['status' => 'failed', 'error' => str($e->getMessage())->limit(250)->toString()])->save();
        }

        return $backup;
    }

    /** @return array<string, list<array>> table => rows */
    public function collect(int $companyId): array
    {
        $map = $this->schemaMap();
        $tables = array_keys($map);
        $out = [];
        $ids = []; // table => list of primary ids included so far

        foreach ($tables as $table) {
            if (! in_array('company_id', $map[$table]['columns'], true)) {
                continue;
            }
            $query = DB::table($table)->where('company_id', $companyId);
            $rows = $table === 'users' ? $query->get(self::SAFE_USER_COLUMNS) : $query->get();
            $out[$table] = $rows->map(fn ($r) => (array) $r)->all();
            $ids[$table] = $rows->pluck('id')->filter()->all();
        }
        $ids['companies'] = [$companyId];

        // Child tables without company_id: rows that reference an included row through a foreign key.
        $pending = array_values(array_diff($tables, array_keys($out)));
        for ($depth = 0; $depth < self::MAX_DEPTH && $pending; $depth++) {
            $next = [];
            foreach ($pending as $table) {
                $links = collect($map[$table]['fks'])->filter(fn ($fk) => ! empty($ids[$fk[1]] ?? null))->values();
                if ($links->isEmpty()) {
                    $next[] = $table;
                    continue;
                }
                $rows = collect();
                foreach ($links as $fk) {
                    foreach (array_chunk($ids[$fk[1]], 1000) as $chunk) {
                        $rows = $rows->merge(DB::table($table)->whereIn($fk[0], $chunk)->get());
                    }
                }
                $rows = in_array('id', $map[$table]['columns'], true) ? $rows->unique('id') : $rows->unique(fn ($r) => json_encode($r));
                $out[$table] = $rows->map(fn ($r) => (array) $r)->values()->all();
                $ids[$table] = $rows->pluck('id')->filter()->values()->all();
            }
            $pending = count($next) < count($pending) ? $next : [];
        }
        ksort($out);

        return $out;
    }

    /**
     * Checks a backup file and compares it with the database.
     *
     * @return array{company: array, generated_at: string, tables: list<array>, warnings: list<string>}
     */
    public function validateFile(string $raw): array
    {
        $data = $this->decode($raw);
        $existing = $this->tables();
        $report = [];
        $warnings = [];

        foreach ($data['tables'] as $table => $rows) {
            if (! in_array($table, $existing, true)) {
                $warnings[] = "Table {$table} no longer exists and will be skipped.";
                continue;
            }
            $rows = (array) $rows;
            $present = 0;
            if (Schema::hasColumn($table, 'id')) {
                $fileIds = array_filter(array_map(fn ($r) => ((array) $r)['id'] ?? null, $rows));
                foreach (array_chunk($fileIds, 1000) as $chunk) {
                    $present += DB::table($table)->whereIn('id', $chunk)->count();
                }
            }
            $report[] = [$table, count($rows), $present, count($rows) - $present];
        }
        if (isset($data['tables']['users'])) {
            $warnings[] = 'User accounts are restored without passwords or 2FA; restored users must use "Forgot password" or be re-activated.';
        }

        return ['company' => $data['meta']['company'], 'generated_at' => $data['meta']['generated_at'] ?? '?', 'tables' => $report, 'warnings' => $warnings];
    }

    /** Merges every row of the backup into the database (insert or update by primary key) in one transaction. */
    public function restore(string $raw): int
    {
        $data = $this->decode($raw);
        $existing = $this->tables();
        $count = 0;

        Schema::disableForeignKeyConstraints();
        try {
            DB::transaction(function () use ($data, $existing, &$count) {
                foreach ($data['tables'] as $table => $rows) {
                    if (! in_array($table, $existing, true) || ! $rows) {
                        continue;
                    }
                    $columns = Schema::getColumnListing($table);
                    $rows = array_map(fn ($r) => array_intersect_key((array) $r, array_flip($columns)), (array) $rows);
                    $hasId = in_array('id', $columns, true);
                    foreach (array_chunk($rows, 500) as $chunk) {
                        foreach ($chunk as $row) {
                            if ($hasId && isset($row['id'])) {
                                DB::table($table)->updateOrInsert(['id' => $row['id']], $row);
                            } else {
                                DB::table($table)->insertOrIgnore($row);
                            }
                            $count++;
                        }
                    }
                }
            });
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        return $count;
    }

    private function decode(string $raw): array
    {
        $json = @gzdecode($raw);
        $data = json_decode($json === false ? $raw : $json, true);
        if (! is_array($data) || ! isset($data['meta']['company']['id'], $data['tables']) || ! is_array($data['tables'])) {
            throw new RuntimeException('Not a backup file created by this system.');
        }
        $companyId = (int) $data['meta']['company']['id'];
        foreach ($data['tables'] as $table => $rows) {
            if (in_array($table, self::SKIP_TABLES, true) || ! preg_match('/^[a-z0-9_]+$/', (string) $table)) {
                throw new RuntimeException("Unexpected table {$table} in the file.");
            }
            foreach ((array) $rows as $row) {
                $row = (array) $row;
                if (array_key_exists('company_id', $row) && $row['company_id'] !== null && (int) $row['company_id'] !== $companyId) {
                    throw new RuntimeException("Table {$table} contains rows of another company.");
                }
                if ($table === 'users' && array_diff(array_keys($row), self::SAFE_USER_COLUMNS)) {
                    throw new RuntimeException('The users table contains columns that a backup never includes.');
                }
            }
        }

        return $data;
    }

    /**
     * Columns and single-column foreign keys of every backed-up table, read in two queries on MySQL
     * (per-table schema calls against information_schema are far too slow on a shared server).
     *
     * @return array<string, array{columns: list<string>, fks: list<array{0: string, 1: string}>}>
     */
    private function schemaMap(): array
    {
        $map = array_fill_keys($this->tables(), ['columns' => [], 'fks' => []]);

        if (DB::getDriverName() === 'mysql') {
            $db = DB::connection()->getDatabaseName();
            foreach (DB::select('SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ?', [$db]) as $r) {
                if (isset($map[$r->t])) {
                    $map[$r->t]['columns'][] = $r->c;
                }
            }
            foreach (DB::select('SELECT TABLE_NAME AS t, COLUMN_NAME AS c, REFERENCED_TABLE_NAME AS p FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL', [$db]) as $r) {
                if (isset($map[$r->t])) {
                    $map[$r->t]['fks'][] = [$r->c, $r->p];
                }
            }

            return $map;
        }

        foreach (array_keys($map) as $table) {
            $map[$table]['columns'] = Schema::getColumnListing($table);
            $map[$table]['fks'] = collect(Schema::getForeignKeys($table))->filter(fn ($fk) => count($fk['columns']) === 1)
                ->map(fn ($fk) => [$fk['columns'][0], $fk['foreign_table']])->values()->all();
        }

        return $map;
    }

    /** @return list<string> */
    private function tables(): array
    {
        $db = DB::connection()->getDatabaseName();

        return collect(Schema::getTables(DB::getDriverName() === 'mysql' ? $db : null))
            ->filter(fn ($t) => in_array($t['schema'] ?? null, [null, 'main', $db], true))
            ->pluck('name')->unique()
            ->reject(fn ($t) => in_array($t, self::SKIP_TABLES, true) || str_starts_with($t, 'sqlite_'))
            ->sort()->values()->all();
    }
}
