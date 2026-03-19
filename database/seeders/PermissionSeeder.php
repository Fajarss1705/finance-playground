<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /**
     * Seed all workflow permissions.
     *
     * Creates permission records via firstOrCreate so this seeder is
     * safe to run multiple times and won't conflict with permissions:sync.
     */
    public function run(): void
    {
        $permissions = array_merge(
            $this->pkTeamPermissions(),
            $this->pkAdminPermissions(),
        );

        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name]);
        }
    }

    // =========================================================================
    // PK Permissions — Team Scope (12)
    // Source of truth: step-review-PK-roles-permissions.md
    // =========================================================================

    /** @return list<string> */
    private function pkTeamPermissions(): array
    {
        return [
            'team.workflows.pk.index',
            'team.workflows.pk.create',
            'team.workflows.pk.show',
            'team.workflows.pk.pk01.show',
            'team.workflows.pk.pk01.draft',
            'team.workflows.pk.pk01.submit',
            'team.workflows.pk.pk04.show',
            'team.workflows.pk.pk04.export.pdf',
            'team.workflows.pk.pk04.export.excel',
            'team.workflows.pk.pk04.export.zip',
            'team.workflows.pk.comment',
            'team.workflows.pk.terminate',
        ];
    }

    // =========================================================================
    // PK Permissions — Admin Scope (23)
    // Source of truth: step-review-PK-roles-permissions.md
    // =========================================================================

    /** @return list<string> */
    private function pkAdminPermissions(): array
    {
        return [
            'admin.workflows.pk.index',
            'admin.workflows.pk.show',
            'admin.workflows.pk.pk01.show',
            'admin.workflows.pk.pk02a.show',
            'admin.workflows.pk.pk02a.approve',
            'admin.workflows.pk.pk02a.reject',
            'admin.workflows.pk.pk02b.show',
            'admin.workflows.pk.pk02b.approve',
            'admin.workflows.pk.pk02b.reject',
            'admin.workflows.pk.pk03.show',
            'admin.workflows.pk.pk03.approve',
            'admin.workflows.pk.pk03.reject',
            'admin.workflows.pk.pk04.show',
            'admin.workflows.pk.pk04.export.pdf',
            'admin.workflows.pk.pk04.export.excel',
            'admin.workflows.pk.pk04.export.zip',
            'admin.workflows.pk.pk05.create',
            'admin.workflows.pk.pk05.show',
            'admin.workflows.pk.pk05.draft',
            'admin.workflows.pk.pk05.submit',
            'admin.workflows.pk.comment',
            'admin.workflows.pk.terminate',
            'admin.workflows.pk.destroy',
        ];
    }
}
