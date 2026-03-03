<?php

namespace App\Concerns;

trait WorkspaceValidationRules
{
    /**
     * Get the validation rules for workspace data.
     *
     * @return array<string, array<int, \Illuminate\Contracts\Validation\Rule|array<mixed>|string>>
     */
    protected function workspaceRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'organization_ids' => ['nullable', 'array'],
            'organization_ids.*' => ['integer', 'exists:organizations,id'],
        ];
    }

    /**
     * Get the custom validation messages for workspace data.
     *
     * @return array<string, string>
     */
    protected function workspaceMessages(): array
    {
        return [
            'name.required' => 'Nama workspace wajib diisi.',
            'name.max' => 'Nama workspace maksimal 255 karakter.',
            'description.max' => 'Deskripsi maksimal 1000 karakter.',
            'organization_ids.array' => 'Format organisasi tidak valid.',
            'organization_ids.*.exists' => 'Organisasi yang dipilih tidak valid.',
        ];
    }
}
