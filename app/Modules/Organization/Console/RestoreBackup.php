<?php

namespace App\Modules\Organization\Console;

use App\Modules\Organization\Models\Backup;
use App\Modules\Organization\Services\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Restores a company backup made under Settings → Data & Backups.
 * Without --force it only validates the file and reports what would change (dry run).
 * With --force rows are merged by primary key (insert new, update existing); newer rows are never deleted.
 */
class RestoreBackup extends Command
{
    protected $signature = 'organization:restore-backup
        {backup : Backup id, or a path to a .json.gz backup file}
        {--force : Apply the restore (otherwise only validate)}';

    protected $description = 'Validate (and with --force, restore) a company data backup';

    public function handle(BackupService $service): int
    {
        $arg = (string) $this->argument('backup');
        if (ctype_digit($arg)) {
            $backup = Backup::withoutGlobalScope('company')->find((int) $arg);
            if (! $backup || ! $backup->path || ! Storage::disk('local')->exists($backup->path)) {
                $this->error('Backup not found or its file is missing.');

                return self::FAILURE;
            }
            $raw = Storage::disk('local')->get($backup->path);
        } elseif (is_file($arg)) {
            $raw = file_get_contents($arg);
        } else {
            $this->error('Pass a backup id or an existing file path.');

            return self::FAILURE;
        }

        try {
            $report = $service->validateFile($raw);
        } catch (Throwable $e) {
            $this->error('Invalid backup: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Backup of company #{$report['company']['id']} {$report['company']['name']} generated {$report['generated_at']}.");
        $this->table(['Table', 'Rows in file', 'Already present', 'New'], $report['tables']);
        foreach ($report['warnings'] as $w) {
            $this->warn($w);
        }

        if (! $this->option('force')) {
            $this->line('Dry run only. Re-run with --force to merge these rows into the database.');

            return self::SUCCESS;
        }

        try {
            $n = $service->restore($raw);
        } catch (Throwable $e) {
            $this->error('Restore failed and was rolled back: '.$e->getMessage());

            return self::FAILURE;
        }
        $this->info("Restored {$n} row(s).");

        return self::SUCCESS;
    }
}
