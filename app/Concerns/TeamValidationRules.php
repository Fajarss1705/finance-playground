<?php

namespace App\Concerns;

trait TeamValidationRules
{
    /**
     * Get the validation rules for team data.
     *
     * @return array<string, array<int, \Illuminate\Contracts\Validation\Rule|array<mixed>|string>>
     */
    protected function teamRules(): array
    {
        return [
            'organization_id' => ['required', 'integer', 'exists:organizations,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Get the custom validation messages for team data.
     *
     * @return array<string, string>
     */
    protected function teamMessages(): array
    {
        return [
            'organization_id.required' => 'Organisasi wajib dipilih.',
            'organization_id.exists' => 'Organisasi yang dipilih tidak valid.',
            'name.required' => 'Nama tim wajib diisi.',
            'name.max' => 'Nama tim maksimal 255 karakter.',
            'description.max' => 'Deskripsi maksimal 1000 karakter.',
        ];
    }
}
