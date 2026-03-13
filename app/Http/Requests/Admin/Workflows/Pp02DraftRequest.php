<?php

namespace App\Http\Requests\Admin\Workflows;

use Illuminate\Foundation\Http\FormRequest;

class Pp02DraftRequest extends FormRequest
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
            'item_kuisioner' => ['nullable', 'array'],
            'item_kuisioner.*.kode' => ['nullable', 'string', 'max:10'],
            'item_kuisioner.*.pertanyaan' => ['nullable', 'string', 'max:255'],
            'item_kuisioner.*.tipe' => ['nullable', 'string', 'max:50'],
            'item_kuisioner.*.satuan' => ['nullable', 'string', 'max:100'],
            'expected_updated_at' => ['required', 'string'],
            'notes' => ['nullable', 'string'],
            'files' => ['nullable', 'array'],
            'files.*' => ['file', 'max:51200'],
        ];
    }
}
