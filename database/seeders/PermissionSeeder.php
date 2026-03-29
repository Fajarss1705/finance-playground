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
            $this->pabdTeamPermissions(),
            $this->pabdAdminPermissions(),
            $this->prblTeamPermissions(),
            $this->prblAdminPermissions(),
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

    // =========================================================================
    // PABD Permissions — Team Scope (16)
    // Source of truth: step-review-PABD-PRBL-roles-permissions.md
    // =========================================================================

    /** @return list<string> */
    private function pabdTeamPermissions(): array
    {
        return [
            'team.workflows.pabd.index',
            'team.workflows.pabd.show',
            'team.workflows.pabd.pabd01.show',
            'team.workflows.pabd.pabd01.draft',
            'team.workflows.pabd.pabd01.submit',
            'team.workflows.pabd.pabd02a.show',
            'team.workflows.pabd.pabd02a.draft',
            'team.workflows.pabd.pabd02a.submit',
            'team.workflows.pabd.pabd02b.show',
            'team.workflows.pabd.pabd03.show',
            'team.workflows.pabd.pabd04.show',
            'team.workflows.pabd.pabd05.show',
            'team.workflows.pabd.pabd05.export.pdf',
            'team.workflows.pabd.pabd05.export.excel',
            'team.workflows.pabd.pabd05.export.zip',
            'team.workflows.pabd.comment',
        ];
    }

    // =========================================================================
    // PABD Permissions — Admin Scope (19)
    // Source of truth: step-review-PABD-PRBL-roles-permissions.md
    // =========================================================================

    /** @return list<string> */
    private function pabdAdminPermissions(): array
    {
        return [
            'admin.workflows.pabd.index',
            'admin.workflows.pabd.show',
            'admin.workflows.pabd.pabd01.show',
            'admin.workflows.pabd.pabd02a.show',
            'admin.workflows.pabd.pabd02b.show',
            'admin.workflows.pabd.pabd02b.draft',
            'admin.workflows.pabd.pabd02b.approve',
            'admin.workflows.pabd.pabd02b.reject',
            'admin.workflows.pabd.pabd03.show',
            'admin.workflows.pabd.pabd03.approve',
            'admin.workflows.pabd.pabd03.reject',
            'admin.workflows.pabd.pabd04.show',
            'admin.workflows.pabd.pabd04.draft',
            'admin.workflows.pabd.pabd04.submit',
            'admin.workflows.pabd.pabd05.show',
            'admin.workflows.pabd.pabd05.export.pdf',
            'admin.workflows.pabd.pabd05.export.excel',
            'admin.workflows.pabd.pabd05.export.zip',
            'admin.workflows.pabd.comment',
            'admin.workflows.pabd.admin_reset',
            'admin.workflows.pabd.admin_create',
        ];
    }

    // =========================================================================
    // PRBL Permissions — Team Scope (16)
    // Source of truth: step-review-PABD-PRBL-roles-permissions.md
    // =========================================================================

    /** @return list<string> */
    private function prblTeamPermissions(): array
    {
        return [
            'team.workflows.prbl.index',
            'team.workflows.prbl.show',
            'team.workflows.prbl.prbl01.show',
            'team.workflows.prbl.prbl01.draft',
            'team.workflows.prbl.prbl01.submit',
            'team.workflows.prbl.prbl02a.show',
            'team.workflows.prbl.prbl02b.show',
            'team.workflows.prbl.prbl03.show',
            'team.workflows.prbl.prbl03.draft',
            'team.workflows.prbl.prbl03.submit',
            'team.workflows.prbl.prbl04.show',
            'team.workflows.prbl.prbl05.show',
            'team.workflows.prbl.prbl05.export.pdf',
            'team.workflows.prbl.prbl05.export.excel',
            'team.workflows.prbl.prbl05.export.zip',
            'team.workflows.prbl.comment',
        ];
    }

    // =========================================================================
    // PRBL Permissions — Admin Scope (18)
    // Source of truth: step-review-PABD-PRBL-roles-permissions.md
    // =========================================================================

    /** @return list<string> */
    private function prblAdminPermissions(): array
    {
        return [
            'admin.workflows.prbl.index',
            'admin.workflows.prbl.show',
            'admin.workflows.prbl.prbl01.show',
            'admin.workflows.prbl.prbl02a.show',
            'admin.workflows.prbl.prbl02a.approve',
            'admin.workflows.prbl.prbl02a.reject',
            'admin.workflows.prbl.prbl02b.show',
            'admin.workflows.prbl.prbl02b.approve',
            'admin.workflows.prbl.prbl02b.reject',
            'admin.workflows.prbl.prbl03.show',
            'admin.workflows.prbl.prbl04.show',
            'admin.workflows.prbl.prbl04.approve',
            'admin.workflows.prbl.prbl04.reject',
            'admin.workflows.prbl.prbl05.show',
            'admin.workflows.prbl.prbl05.export.pdf',
            'admin.workflows.prbl.prbl05.export.excel',
            'admin.workflows.prbl.prbl05.export.zip',
            'admin.workflows.prbl.comment',
        ];
    }
}
