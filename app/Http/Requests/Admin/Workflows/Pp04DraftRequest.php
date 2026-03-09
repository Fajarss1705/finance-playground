<?php

namespace App\Http\Requests\Admin\Workflows;

use Illuminate\Foundation\Http\FormRequest;

class Pp04DraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'attach_file_ids' => ['nullable', 'array'],
            'attach_file_ids.*' => ['integer', 'exists:files,id'],
            'expected_updated_at' => ['required', 'string'],
            'notes' => ['nullable', 'string'],
            'files' => ['nullable', 'array'],
            'files.*' => ['file', 'max:51200'],
        ];
    }
}
