<?php

namespace App\Http\Requests\Workflows;

use Illuminate\Foundation\Http\FormRequest;

class Prbl01DraftRequest extends FormRequest
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
            'items' => ['nullable', 'array'],
            'items.*.prbl01_item_kegiatan_id' => ['nullable', 'integer'],
            'items.*.masalah' => ['nullable', 'string'],
            'items.*.langkah_penanganan' => ['nullable', 'string'],
            'items.*.harapan' => ['nullable', 'string'],
            'items.*.catatan_tim' => ['nullable', 'string'],
            'items.*.kuisioner' => ['nullable', 'array'],
            'items.*.kuisioner.*.prbl01_item_kuisioner_id' => ['nullable', 'integer'],
            'items.*.kuisioner.*.jawaban' => ['nullable', 'string'],
            'realisasi' => ['nullable', 'array'],
            'realisasi.*.prbl01_item_realisasi_id' => ['nullable', 'integer'],
            'realisasi.*.nominal_realisasi' => ['nullable', 'numeric', 'min:0'],
            'realisasi.*.komentar_realisasi' => ['nullable', 'string'],
            'expected_updated_at' => ['required', 'string'],
            'notes' => ['nullable', 'string'],
            'files' => ['nullable', 'array'],
            'files.*' => ['file', 'max:51200'],
        ];
    }
}
