<?php

namespace App\Http\Requests\Workflows;

use Illuminate\Foundation\Http\FormRequest;

class Pk05SubmitRequest extends FormRequest
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
            'draft_data.kode_kategori' => ['required', 'string', 'max:10'],
            'draft_data.nama_program' => ['required', 'string', 'max:255'],
            'draft_data.deskripsi_program' => ['required', 'string'],
            'draft_data.tujuan_program' => ['required', 'string'],
            'draft_data.kegiatan' => ['required', 'array', 'min:1'],
            'draft_data.kegiatan.*.pk04_kegiatan_id' => ['nullable', 'integer'],
            'draft_data.kegiatan.*.nama_kegiatan' => ['required', 'string', 'max:255'],
            'draft_data.kegiatan.*.bulan' => ['required', 'integer', 'min:1', 'max:12'],
            'draft_data.kegiatan.*.anggaran' => ['required', 'array', 'min:1'],
            'draft_data.kegiatan.*.anggaran.*.pk04_anggaran_id' => ['nullable', 'integer'],
            'draft_data.kegiatan.*.anggaran.*.kode_bidang' => ['required', 'string', 'max:10'],
            'draft_data.kegiatan.*.anggaran.*.kode_sub_bidang' => ['required', 'string', 'max:10'],
            'draft_data.kegiatan.*.anggaran.*.kode_jenis' => ['required', 'string', 'max:10'],
            'draft_data.kegiatan.*.anggaran.*.mata_anggaran' => ['required', 'string', 'max:255'],
            'draft_data.kegiatan.*.anggaran.*.deskripsi_pk' => ['required', 'string'],
            'draft_data.kegiatan.*.anggaran.*.nominal_anggaran' => ['required', 'numeric', 'min:0'],
            'draft_data.kegiatan.*.anggaran.*.is_locked' => ['nullable', 'boolean'],
            'draft_data.kegiatan.*.kuisioner' => ['nullable', 'array'],
            'draft_data.kegiatan.*.kuisioner.*.pk04_kuisioner_id' => ['nullable', 'integer'],
            'draft_data.kegiatan.*.kuisioner.*.kode_kuisioner' => ['nullable', 'string', 'max:10'],
            'draft_data.kegiatan.*.kuisioner.*.pertanyaan' => ['required', 'string', 'max:255'],
            'draft_data.kegiatan.*.kuisioner.*.tipe' => ['required', 'string', 'max:50'],
            'draft_data.kegiatan.*.kuisioner.*.satuan' => ['required_if:draft_data.kegiatan.*.kuisioner.*.tipe,Kuantitatif', 'nullable', 'string', 'max:100'],
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
            'draft_data.kode_kategori.required' => 'Kategori pelayanan wajib dipilih.',
            'draft_data.nama_program.required' => 'Nama program wajib diisi.',
            'draft_data.deskripsi_program.required' => 'Deskripsi program wajib diisi.',
            'draft_data.tujuan_program.required' => 'Tujuan program wajib diisi.',
            'draft_data.kegiatan.required' => 'Minimal 1 kegiatan wajib diisi.',
            'draft_data.kegiatan.min' => 'Minimal 1 kegiatan wajib diisi.',
            'draft_data.kegiatan.*.nama_kegiatan.required' => 'Nama kegiatan wajib diisi.',
            'draft_data.kegiatan.*.bulan.required' => 'Bulan wajib dipilih.',
            'draft_data.kegiatan.*.anggaran.required' => 'Minimal 1 anggaran per kegiatan.',
            'draft_data.kegiatan.*.anggaran.min' => 'Minimal 1 anggaran per kegiatan.',
            'draft_data.kegiatan.*.anggaran.*.kode_bidang.required' => 'Bidang wajib dipilih.',
            'draft_data.kegiatan.*.anggaran.*.kode_sub_bidang.required' => 'Sub Bidang wajib dipilih.',
            'draft_data.kegiatan.*.anggaran.*.kode_jenis.required' => 'Jenis wajib dipilih.',
            'draft_data.kegiatan.*.anggaran.*.mata_anggaran.required' => 'Mata anggaran wajib diisi.',
            'draft_data.kegiatan.*.anggaran.*.deskripsi_pk.required' => 'Deskripsi wajib diisi.',
            'draft_data.kegiatan.*.anggaran.*.nominal_anggaran.required' => 'Nominal wajib diisi.',
            'draft_data.kegiatan.*.anggaran.*.nominal_anggaran.min' => 'Nominal tidak boleh negatif.',
            'draft_data.kegiatan.*.kuisioner.*.pertanyaan.required' => 'Pertanyaan wajib diisi.',
            'draft_data.kegiatan.*.kuisioner.*.tipe.required' => 'Tipe wajib diisi.',
            'draft_data.kegiatan.*.kuisioner.*.satuan.required_if' => 'Satuan wajib diisi untuk tipe Kuantitatif.',
        ];
    }
}
