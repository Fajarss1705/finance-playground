<?php

namespace App\Http\Requests\Workflows;

use Illuminate\Foundation\Http\FormRequest;

class Pabd01SubmitRequest extends FormRequest
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
            'ada_perubahan' => ['required', 'boolean'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.pabd01_item_anggaran_id' => ['required', 'integer', 'exists:pabd01_item_anggaran,id'],
            'items.*.dicairkan' => ['required', 'boolean'],
            'expected_updated_at' => ['required', 'string'],
            'notes' => ['nullable', 'string'],
            'files' => ['nullable', 'array'],
            'files.*' => ['file', 'max:51200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ada_perubahan.required' => 'Pilih apakah ada perubahan anggaran.',
            'items.required' => 'Daftar item anggaran tidak boleh kosong.',
            'items.min' => 'Minimal 1 item anggaran harus ada.',
            'items.*.pabd01_item_anggaran_id.required' => 'ID item anggaran tidak valid.',
            'items.*.pabd01_item_anggaran_id.exists' => 'Item anggaran tidak ditemukan.',
            'items.*.dicairkan.required' => 'Status pencairan item harus diisi.',
        ];
    }
}
