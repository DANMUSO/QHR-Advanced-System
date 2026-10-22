<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * Discovers self-contained HR modules in app/Modules/<Name>/module.php.
 *
 * A manifest returns an array with any of:
 *   'name'               => 'Leave'
 *   'order'              => 30                         // load / seed order
 *   'permissions'        => ['Group' => ['perm.key' => 'Label']]
 *   'role_permissions'   => ['employee' => ['perm.key'], ...]
 *   'widgets'            => [WidgetClass::class, ...]  // dashboard widgets
 *   'approval_providers' => [ProviderClass::class, ...]
 *   'schedule'           => fn (Illuminate\Console\Scheduling\Schedule $s) => ...
 *   'boot'               => fn () => ...               // e.g. Employee::resolveRelationUsing(...)
 *   'demo_seeder'        => SeederClass::class         // run by `db:seed` in local env
 *   'team_status'        => fn (Collection $employeeIds) => [id => 'on_leave'|'absent'|'present'] // My Team widget
 *   'payroll_inputs'     => [ProviderClass::class]     // implements App\Contracts\PayrollInputProvider
 *
 * Module routes live in app/Modules/<Name>/routes.php (loaded inside the authenticated API group)
 * and migrations in app/Modules/<Name>/database/migrations.
 */
class ModuleRegistry
{
    private static ?Collection $modules = null;

    /** @return Collection<string, array{path: string, manifest: array}> */
    public static function all(): Collection
    {
        if (static::$modules !== null) {
            return static::$modules;
        }

        return static::$modules = collect(glob(app_path('Modules/*/module.php')) ?: [])
            ->mapWithKeys(function (string $file) {
                $manifest = require $file;

                return [basename(dirname($file)) => ['path' => dirname($file), 'manifest' => is_array($manifest) ? $manifest : []]];
            })
            ->sortBy(fn ($m) => $m['manifest']['order'] ?? 100);
    }

    /** Flattened list of a manifest key across all modules (for list-valued keys). */
    public static function collect(string $key): array
    {
        return static::all()->flatMap(fn ($m) => $m['manifest'][$key] ?? [])->values()->all();
    }

    /** Callables registered under a key (boot, schedule). */
    public static function callbacks(string $key): array
    {
        return static::all()->map(fn ($m) => $m['manifest'][$key] ?? null)->filter()->values()->all();
    }

    public static function routeFiles(): array
    {
        return static::all()->map(fn ($m) => $m['path'].'/routes.php')->filter(fn ($f) => is_file($f))->values()->all();
    }

    public static function migrationPaths(): array
    {
        return static::all()->map(fn ($m) => $m['path'].'/database/migrations')->filter(fn ($p) => is_dir($p))->values()->all();
    }

    public static function permissions(): array
    {
        $groups = config('hr.permissions', []);
        foreach (static::all() as $m) {
            foreach ($m['manifest']['permissions'] ?? [] as $group => $perms) {
                $groups[$group] = array_merge($groups[$group] ?? [], $perms);
            }
        }

        return $groups;
    }

    /** Role => permission list, merging config/hr.php with module manifests. */
    public static function rolePermissions(): array
    {
        $roles = collect(config('hr.roles', []))->map(fn ($r) => $r['permissions'])->all();
        foreach (static::all() as $m) {
            foreach ($m['manifest']['role_permissions'] ?? [] as $role => $perms) {
                if (($roles[$role] ?? null) === '*') {
                    continue;
                }
                $roles[$role] = array_values(array_unique(array_merge($roles[$role] ?? [], $perms)));
            }
        }

        return $roles;
    }
}
