<?php

namespace App\Http\Requests\Workflows;

use Illuminate\Foundation\Http\FormRequest;

class Pabd03RejectRequest extends FormRequest
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
            'expected_updated_at' => ['required', 'string'],
            'notes' => ['required', 'string', 'min:10'],
            'files' => ['nullable', 'array'],
            'files.*' => ['file', 'max:25600'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'notes.required' => 'Alasan penolakan wajib diisi.',
            'notes.min' => 'Alasan penolakan minimal 10 karakter.',
        ];
    }
}
