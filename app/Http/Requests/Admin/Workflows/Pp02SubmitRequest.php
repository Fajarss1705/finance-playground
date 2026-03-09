<?php

namespace App\Http\Requests\Admin\Workflows;

use Illuminate\Foundation\Http\FormRequest;

class Pp02SubmitRequest extends FormRequest
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
            'item_kuisioner' => ['required', 'array', 'min:1'],
            'item_kuisioner.*.kode' => ['required', 'string', 'max:10'],
            'item_kuisioner.*.pertanyaan' => ['required', 'string', 'max:255'],
            'item_kuisioner.*.tipe' => ['required', 'string', 'max:50'],
            'item_kuisioner.*.satuan' => ['required_if:item_kuisioner.*.tipe,Kuantitatif', 'nullable', 'string', 'max:100'],
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
            'item_kuisioner.required' => 'Minimal 1 pertanyaan kuisioner wajib diisi.',
            'item_kuisioner.min' => 'Minimal 1 pertanyaan kuisioner wajib diisi.',
            'item_kuisioner.*.kode.required' => 'Kode wajib diisi.',
            'item_kuisioner.*.pertanyaan.required' => 'Pertanyaan wajib diisi.',
            'item_kuisioner.*.tipe.required' => 'Tipe wajib diisi.',
            'item_kuisioner.*.satuan.required_if' => 'Satuan wajib diisi untuk tipe Kuantitatif.',
        ];
    }
}
