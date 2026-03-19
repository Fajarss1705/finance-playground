<?php

namespace App\Http\Requests\Workflows;

use Illuminate\Foundation\Http\FormRequest;

class Pabd02bApproveRequest extends FormRequest
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
            'items' => ['required', 'array'],
            'items.*.pabd02b_item_review_id' => ['required', 'integer', 'exists:pabd02b_item_review,id'],
            'items.*.komentar_approval' => ['nullable', 'string'],
            'expected_updated_at' => ['required', 'string'],
            'notes' => ['nullable', 'string'],
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
            'items.required' => 'Data review item harus disertakan.',
            'items.*.pabd02b_item_review_id.required' => 'ID item review harus disertakan.',
            'items.*.pabd02b_item_review_id.exists' => 'Item review tidak ditemukan.',
        ];
    }
}
