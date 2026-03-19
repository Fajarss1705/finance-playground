<?php

namespace App\Http\Requests\Workflows;

use Illuminate\Foundation\Http\FormRequest;

class Pk05DraftRequest extends FormRequest
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
            'draft_data' => ['required', 'array'],
            'draft_data.kode_kategori' => ['nullable', 'string', 'max:10'],
            'draft_data.nama_program' => ['nullable', 'string', 'max:255'],
            'draft_data.deskripsi_program' => ['nullable', 'string'],
            'draft_data.tujuan_program' => ['nullable', 'string'],
            'draft_data.kegiatan' => ['nullable', 'array'],
            'draft_data.kegiatan.*.pk04_kegiatan_id' => ['nullable', 'integer'],
            'draft_data.kegiatan.*.nama_kegiatan' => ['nullable', 'string', 'max:255'],
            'draft_data.kegiatan.*.bulan' => ['nullable', 'integer', 'min:1', 'max:12'],
            'draft_data.kegiatan.*.anggaran' => ['nullable', 'array'],
            'draft_data.kegiatan.*.anggaran.*.pk04_anggaran_id' => ['nullable', 'integer'],
            'draft_data.kegiatan.*.anggaran.*.kode_bidang' => ['nullable', 'string', 'max:10'],
            'draft_data.kegiatan.*.anggaran.*.kode_sub_bidang' => ['nullable', 'string', 'max:10'],
            'draft_data.kegiatan.*.anggaran.*.kode_jenis' => ['nullable', 'string', 'max:10'],
            'draft_data.kegiatan.*.anggaran.*.mata_anggaran' => ['nullable', 'string', 'max:255'],
            'draft_data.kegiatan.*.anggaran.*.deskripsi_pk' => ['nullable', 'string'],
            'draft_data.kegiatan.*.anggaran.*.nominal_anggaran' => ['nullable', 'numeric', 'min:0'],
            'draft_data.kegiatan.*.anggaran.*.is_locked' => ['nullable', 'boolean'],
            'draft_data.kegiatan.*.kuisioner' => ['nullable', 'array'],
            'draft_data.kegiatan.*.kuisioner.*.pk04_kuisioner_id' => ['nullable', 'integer'],
            'draft_data.kegiatan.*.kuisioner.*.kode_kuisioner' => ['nullable', 'string', 'max:10'],
            'draft_data.kegiatan.*.kuisioner.*.pertanyaan' => ['nullable', 'string', 'max:255'],
            'draft_data.kegiatan.*.kuisioner.*.tipe' => ['nullable', 'string', 'max:50'],
            'draft_data.kegiatan.*.kuisioner.*.satuan' => ['nullable', 'string', 'max:100'],
            'expected_updated_at' => ['required', 'string'],
            'notes' => ['nullable', 'string'],
            'files' => ['nullable', 'array'],
            'files.*' => ['file', 'max:51200'],
        ];
    }
}
