<?php

namespace App\Console\Commands;

use App\Support\ModuleRegistry;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class SyncPermissions extends Command
{
    protected $signature = 'hr:sync-permissions';

    protected $description = 'Create permissions and role templates from config/hr.php and module manifests';

    public function handle(): int
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $all = collect(ModuleRegistry::permissions())->flatMap(fn ($perms) => array_keys($perms))->unique()->values();
        $all->each(fn ($name) => Permission::findOrCreate($name, 'web'));
        Permission::whereNotIn('name', $all)->delete();

        foreach (ModuleRegistry::rolePermissions() as $name => $perms) {
            Role::findOrCreate($name, 'web')->syncPermissions($perms === '*' ? $all->all() : $perms);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->info("Synced {$all->count()} permissions across ".count(ModuleRegistry::rolePermissions()).' roles.');

        return self::SUCCESS;
    }
}
