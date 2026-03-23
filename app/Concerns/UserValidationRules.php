<?php

namespace App\Concerns;

use Illuminate\Validation\Rules\Password;

trait UserValidationRules
{
    use PasswordValidationRules;
    use ProfileValidationRules;

    /**
     * Get the validation rules for storing a new user.
     *
     * @return array<string, array<int, \Illuminate\Contracts\Validation\Rule|array<mixed>|string>>
     */
    protected function userStoreRules(): array
    {
        return [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
            'phone_number' => ['nullable', 'string', 'max:255'],
            'jabatan' => ['nullable', 'string', 'max:255'],
            'role_ids' => ['nullable', 'array'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
        ];
    }

    /**
     * Get the validation rules for updating an existing user.
     *
     * @return array<string, array<int, \Illuminate\Contracts\Validation\Rule|array<mixed>|string>>
     */
    protected function userUpdateRules(int $userId): array
    {
        return [
            ...$this->profileRules($userId),
            'password' => ['nullable', 'string', Password::default(), 'confirmed'],
            'phone_number' => ['nullable', 'string', 'max:255'],
            'jabatan' => ['nullable', 'string', 'max:255'],
            'role_ids' => ['nullable', 'array'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
        ];
    }

    /**
     * Get the custom validation messages for user data.
     *
     * @return array<string, string>
     */
    protected function userMessages(): array
    {
        return [
            'name.required' => 'Nama wajib diisi.',
            'name.max' => 'Nama maksimal 255 karakter.',
            'email.required' => 'Email wajib diisi.',
            'email.email' => 'Format email tidak valid.',
            'email.max' => 'Email maksimal 255 karakter.',
            'email.unique' => 'Email sudah digunakan.',
            'password.required' => 'Password wajib diisi.',
            'password.confirmed' => 'Konfirmasi password tidak cocok.',
            'phone_number.required' => 'Nomor telepon wajib diisi.',
            'phone_number.regex' => 'Format nomor telepon tidak valid (contoh: 08123456789).',
            'phone_number.max' => 'Nomor telepon maksimal 20 karakter.',
            'jabatan.required' => 'Jabatan wajib diisi.',
            'jabatan.max' => 'Jabatan maksimal 255 karakter.',
            'role_ids.array' => 'Format role tidak valid.',
            'role_ids.*.exists' => 'Role yang dipilih tidak valid.',
        ];
    }
}
