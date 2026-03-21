<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SwitchRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'role_id' => ['required', 'integer', 'exists:roles,id'],
            'workspace_id' => ['required', 'integer', 'exists:workspaces,id'],
            'redirect_to' => ['sometimes', 'nullable', 'string'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $user = $this->user();
            $roleId = $this->input('role_id');
            $workspaceId = $this->input('workspace_id');

            if (! $user->roles()->where('roles.id', $roleId)->exists()) {
                $validator->errors()->add('role_id', 'Role ini bukan milik Anda.');

                return;
            }

            $role = $user->roles()->with('team.organization.workspaces')->find($roleId);
            $workspaceIds = $role->team->organization->workspaces->pluck('id');

            if (! $workspaceIds->contains($workspaceId)) {
                $validator->errors()->add('workspace_id', 'Workspace ini tidak dapat diakses dengan role tersebut.');
            }
        });
    }
}
