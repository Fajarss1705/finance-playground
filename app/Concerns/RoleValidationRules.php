<?php

namespace App\Concerns;

trait RoleValidationRules
{
    /**
     * Get the validation rules for role data.
     *
     * @return array<string, array<int, \Illuminate\Contracts\Validation\Rule|array<mixed>|string>>
     */
    protected function roleRules(): array
    {
        return [
            'team_id' => ['required', 'integer', 'exists:teams,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'permission_ids' => ['nullable', 'array'],
            'permission_ids.*' => ['integer', 'exists:permissions,id'],
        ];
    }

    /**
     * Get the custom validation messages for role data.
     *
     * @return array<string, string>
     */
    protected function roleMessages(): array
    {
        return [
            'team_id.required' => 'Tim wajib dipilih.',
            'team_id.exists' => 'Tim yang dipilih tidak valid.',
            'name.required' => 'Nama role wajib diisi.',
            'name.max' => 'Nama role maksimal 255 karakter.',
            'description.max' => 'Deskripsi maksimal 1000 karakter.',
            'permission_ids.array' => 'Format permission tidak valid.',
            'permission_ids.*.exists' => 'Permission yang dipilih tidak valid.',
        ];
    }
}
