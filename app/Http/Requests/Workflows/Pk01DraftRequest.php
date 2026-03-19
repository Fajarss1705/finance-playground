<?php

namespace App\Http\Requests\Workflows;

use Illuminate\Foundation\Http\FormRequest;

class Pk01DraftRequest extends FormRequest
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
            'kode_kategori' => ['nullable', 'string', 'max:10'],
            'nama_program' => ['nullable', 'string', 'max:255'],
            'deskripsi_program' => ['nullable', 'string'],
            'tujuan_program' => ['nullable', 'string'],
            'kegiatan' => ['nullable', 'array'],
            'kegiatan.*.nama_kegiatan' => ['nullable', 'string', 'max:255'],
            'kegiatan.*.bulan' => ['nullable', 'integer', 'between:1,12'],
            'kegiatan.*.anggaran' => ['nullable', 'array'],
            'kegiatan.*.anggaran.*.kode_bidang' => ['nullable', 'string', 'max:10'],
            'kegiatan.*.anggaran.*.kode_sub_bidang' => ['nullable', 'string', 'max:10'],
            'kegiatan.*.anggaran.*.kode_jenis' => ['nullable', 'string', 'max:10'],
            'kegiatan.*.anggaran.*.mata_anggaran' => ['nullable', 'string', 'max:255'],
            'kegiatan.*.anggaran.*.deskripsi_pk' => ['nullable', 'string'],
            'kegiatan.*.anggaran.*.nominal_anggaran' => ['nullable', 'numeric', 'min:0'],
            'kegiatan.*.kuisioner' => ['nullable', 'array'],
            'kegiatan.*.kuisioner.*.pertanyaan' => ['nullable', 'string', 'max:255'],
            'kegiatan.*.kuisioner.*.tipe' => ['nullable', 'string', 'max:50'],
            'kegiatan.*.kuisioner.*.satuan' => ['nullable', 'string', 'max:100'],
            'kegiatan.*.kuisioner.*.kode_kuisioner' => ['nullable', 'string', 'max:10'],
            'expected_updated_at' => ['required', 'string'],
            'notes' => ['nullable', 'string'],
            'files' => ['nullable', 'array'],
            'files.*' => ['file', 'max:51200'],
        ];
    }
}
