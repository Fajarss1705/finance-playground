<?php

namespace App\Http\Requests\Workflows;

use Illuminate\Foundation\Http\FormRequest;

class Prbl01SubmitRequest extends FormRequest
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
            'items' => ['required', 'array', 'min:1'],
            'items.*.prbl01_item_kegiatan_id' => ['required', 'integer', 'exists:prbl01_item_kegiatan,id'],
            'items.*.masalah' => ['nullable', 'string'],
            'items.*.langkah_penanganan' => ['nullable', 'string'],
            'items.*.harapan' => ['nullable', 'string'],
            'items.*.catatan_tim' => ['nullable', 'string'],
            'items.*.kuisioner' => ['nullable', 'array'],
            'items.*.kuisioner.*.prbl01_item_kuisioner_id' => ['required', 'integer', 'exists:prbl01_item_kuisioner,id'],
            'items.*.kuisioner.*.jawaban' => ['nullable', 'string'],
            'realisasi' => ['required', 'array', 'min:1'],
            'realisasi.*.prbl01_item_realisasi_id' => ['required', 'integer', 'exists:prbl01_item_realisasi,id'],
            'realisasi.*.nominal_realisasi' => ['required', 'numeric', 'min:0'],
            'realisasi.*.komentar_realisasi' => ['nullable', 'string'],
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
            'items.required' => 'Data kegiatan tidak boleh kosong.',
            'items.min' => 'Minimal 1 item kegiatan harus ada.',
            'items.*.prbl01_item_kegiatan_id.required' => 'ID item kegiatan tidak valid.',
            'items.*.prbl01_item_kegiatan_id.exists' => 'Item kegiatan tidak ditemukan.',
            'realisasi.required' => 'Data realisasi tidak boleh kosong.',
            'realisasi.min' => 'Minimal 1 item realisasi harus ada.',
            'realisasi.*.prbl01_item_realisasi_id.required' => 'ID item realisasi tidak valid.',
            'realisasi.*.prbl01_item_realisasi_id.exists' => 'Item realisasi tidak ditemukan.',
            'realisasi.*.nominal_realisasi.required' => 'Nominal realisasi wajib diisi.',
            'realisasi.*.nominal_realisasi.numeric' => 'Nominal realisasi harus berupa angka.',
            'realisasi.*.nominal_realisasi.min' => 'Nominal realisasi tidak boleh negatif.',
        ];
    }
}
