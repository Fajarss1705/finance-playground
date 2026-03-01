<?php

namespace App\Console\Commands;

use App\Models\Permission;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;

class SyncPermissions extends Command
{
    protected $signature = 'permissions:sync
        {--remove-stale : Remove permissions that no longer match any route}
        {--dry-run : Show what would happen without making changes}';

    protected $description = 'Sync permissions table with named routes that use auth middleware';

    public function handle(): int
    {
        $routeNames = $this->getAuthRouteNames();
        $existingNames = Permission::query()->pluck('name')->all();

        $toCreate = array_diff($routeNames, $existingNames);
        $stale = array_diff($existingNames, $routeNames);
        $unchanged = array_intersect($existingNames, $routeNames);

        $isDryRun = $this->option('dry-run');
        $removeStale = $this->option('remove-stale');

        if ($isDryRun) {
            $this->components->info('Dry run — no changes will be made.');
        }

        if (count($toCreate) > 0) {
            if (! $isDryRun) {
                $records = array_map(fn (string $name) => [
                    'name' => $name,
                    'created_at' => now(),
                    'updated_at' => now(),
                ], $toCreate);

                Permission::query()->insert($records);
            }

            $this->components->info(count($toCreate).' permission(s) to create:');
            $this->table(['Name'], array_map(fn ($n) => [$n], $toCreate));
        }

        if (count($stale) > 0 && $removeStale) {
            if (! $isDryRun) {
                Permission::query()->whereIn('name', $stale)->delete();
            }

            $this->components->info(count($stale).' stale permission(s) to remove:');
            $this->table(['Name'], array_map(fn ($n) => [$n], $stale));
        } elseif (count($stale) > 0) {
            $this->components->warn(count($stale).' stale permission(s) found (use --remove-stale to remove):');
            $this->table(['Name'], array_map(fn ($n) => [$n], $stale));
        }

        if (count($unchanged) > 0) {
            $this->components->info(count($unchanged).' permission(s) unchanged.');
        }

        if (count($toCreate) === 0 && count($stale) === 0) {
            $this->components->info('Permissions are already in sync.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function getAuthRouteNames(): array
    {
        $names = [];

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();

            if (! $name) {
                continue;
            }

            $middleware = collect(Route::gatherRouteMiddleware($route));

            if ($middleware->contains('auth')) {
                $names[] = $name;
            }
        }

        sort($names);

        return $names;
    }
}
