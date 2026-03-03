<?php

namespace App\Concerns;

trait OrganizationValidationRules
{
    /**
     * Get the validation rules for organization data.
     *
     * @return array<string, array<int, \Illuminate\Contracts\Validation\Rule|array<mixed>|string>>
     */
    protected function organizationRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Get the custom validation messages for organization data.
     *
     * @return array<string, string>
     */
    protected function organizationMessages(): array
    {
        return [
            'name.required' => 'Nama organisasi wajib diisi.',
            'name.max' => 'Nama organisasi maksimal 255 karakter.',
            'description.max' => 'Deskripsi maksimal 1000 karakter.',
        ];
    }
}
