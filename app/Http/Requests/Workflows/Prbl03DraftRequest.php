<?php

namespace App\Http\Requests\Workflows;

use Illuminate\Foundation\Http\FormRequest;

class Prbl03DraftRequest extends FormRequest
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
            'keterangan' => ['nullable', 'string'],
            'bukti_transfer_files' => ['nullable', 'array'],
            'bukti_transfer_files.*' => ['file', 'max:25600', 'mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx'],
            'foto_nota_files' => ['nullable', 'array'],
            'foto_nota_files.*' => ['file', 'max:25600', 'mimes:jpg,jpeg,png,webp,pdf'],
            'remove_bukti_transfer_ids' => ['nullable', 'array'],
            'remove_bukti_transfer_ids.*' => ['integer'],
            'remove_foto_nota_ids' => ['nullable', 'array'],
            'remove_foto_nota_ids.*' => ['integer'],
            'notes' => ['nullable', 'string'],
            'files' => ['nullable', 'array'],
            'files.*' => ['file', 'max:25600'],
        ];
    }
}
