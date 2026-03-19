<?php

namespace App\Http\Requests\Workflows;

use Illuminate\Foundation\Http\FormRequest;

class Pabd01DraftRequest extends FormRequest
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
            'ada_perubahan' => ['nullable', 'boolean'],
            'items' => ['nullable', 'array'],
            'items.*.pabd01_item_anggaran_id' => ['nullable', 'integer'],
            'items.*.dicairkan' => ['nullable', 'boolean'],
            'expected_updated_at' => ['required', 'string'],
            'notes' => ['nullable', 'string'],
            'files' => ['nullable', 'array'],
            'files.*' => ['file', 'max:51200'],
        ];
    }
}
