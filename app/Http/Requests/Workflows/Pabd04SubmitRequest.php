<?php

namespace App\Http\Requests\Workflows;

use Illuminate\Foundation\Http\FormRequest;

class Pabd04SubmitRequest extends FormRequest
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
            'bukti_transfer_files' => ['nullable', 'array'],
            'bukti_transfer_files.*' => ['file', 'max:25600', 'mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx'],
            'remove_file_ids' => ['nullable', 'array'],
            'remove_file_ids.*' => ['integer'],
            'notes' => ['nullable', 'string'],
            'files' => ['nullable', 'array'],
            'files.*' => ['file', 'max:25600'],
        ];
    }
}
