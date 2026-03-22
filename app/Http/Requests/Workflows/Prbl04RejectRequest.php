<?php

namespace App\Http\Requests\Workflows;

use Illuminate\Foundation\Http\FormRequest;

class Prbl04RejectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'expected_updated_at' => ['required', 'string'],
            'rejection_target' => ['required', 'string', 'in:PRBL03,PRBL01'],
            'notes' => ['required', 'string', 'min:10'],
            'files' => ['nullable', 'array'],
            'files.*' => ['file', 'max:25600'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'rejection_target.required' => 'Pilih tujuan penolakan.',
            'rejection_target.in' => 'Tujuan penolakan tidak valid.',
            'notes.required' => 'Catatan penolakan wajib diisi.',
            'notes.min' => 'Catatan penolakan minimal :min karakter.',
        ];
    }
}
