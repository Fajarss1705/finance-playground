<?php

namespace App\Services;

use App\Models\Role;
use App\Models\Workspace;
use Illuminate\Support\Facades\Session;

class ActiveSessionService
{
    private const KEY_ROLE_ID = 'active_role_id';

    private const KEY_WORKSPACE_ID = 'active_workspace_id';

    private const KEY_PERMISSIONS = 'active_permissions';

    public function switchTo(Role $role, Workspace $workspace): void
    {
        Session::put(self::KEY_ROLE_ID, $role->id);
        Session::put(self::KEY_WORKSPACE_ID, $workspace->id);
        Session::put(self::KEY_PERMISSIONS, $role->permissions()->pluck('name')->all());
    }

    public function hasActiveSession(): bool
    {
        return Session::has(self::KEY_ROLE_ID) && Session::has(self::KEY_WORKSPACE_ID);
    }

    public function getActiveRoleId(): ?int
    {
        return Session::get(self::KEY_ROLE_ID);
    }

    public function getActiveWorkspaceId(): ?int
    {
        return Session::get(self::KEY_WORKSPACE_ID);
    }

    /**
     * @return array<int, string>
     */
    public function getActivePermissions(): array
    {
        return Session::get(self::KEY_PERMISSIONS, []);
    }

    public function refreshPermissions(): void
    {
        $roleId = $this->getActiveRoleId();

        if ($roleId) {
            $role = Role::find($roleId);
            Session::put(self::KEY_PERMISSIONS, $role ? $role->permissions()->pluck('name')->all() : []);
        }
    }

    public function clearActiveSession(): void
    {
        Session::forget([self::KEY_ROLE_ID, self::KEY_WORKSPACE_ID, self::KEY_PERMISSIONS]);
    }
}
