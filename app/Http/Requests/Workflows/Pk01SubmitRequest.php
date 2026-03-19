<?php

namespace App\Http\Requests\Workflows;

use Illuminate\Foundation\Http\FormRequest;

class Pk01SubmitRequest extends FormRequest
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
            'kode_kategori' => ['required', 'string', 'max:10'],
            'nama_program' => ['required', 'string', 'max:255'],
            'deskripsi_program' => ['required', 'string'],
            'tujuan_program' => ['required', 'string'],
            'kegiatan' => ['required', 'array', 'min:1'],
            'kegiatan.*.nama_kegiatan' => ['required', 'string', 'max:255'],
            'kegiatan.*.bulan' => ['required', 'integer', 'between:1,12'],
            'kegiatan.*.anggaran' => ['required', 'array', 'min:1'],
            'kegiatan.*.anggaran.*.kode_bidang' => ['required', 'string', 'max:10'],
            'kegiatan.*.anggaran.*.kode_sub_bidang' => ['required', 'string', 'max:10'],
            'kegiatan.*.anggaran.*.kode_jenis' => ['required', 'string', 'max:10'],
            'kegiatan.*.anggaran.*.mata_anggaran' => ['required', 'string', 'max:255'],
            'kegiatan.*.anggaran.*.deskripsi_pk' => ['nullable', 'string'],
            'kegiatan.*.anggaran.*.nominal_anggaran' => ['required', 'numeric', 'min:0'],
            'kegiatan.*.kuisioner' => ['nullable', 'array'],
            'kegiatan.*.kuisioner.*.pertanyaan' => ['required', 'string', 'max:255'],
            'kegiatan.*.kuisioner.*.tipe' => ['required', 'string', 'max:50'],
            'kegiatan.*.kuisioner.*.satuan' => ['nullable', 'string', 'max:100'],
            'kegiatan.*.kuisioner.*.kode_kuisioner' => ['nullable', 'string', 'max:10'],
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
            'kode_kategori.required' => 'Kategori pelayanan wajib dipilih.',
            'nama_program.required' => 'Nama program wajib diisi.',
            'deskripsi_program.required' => 'Deskripsi program wajib diisi.',
            'tujuan_program.required' => 'Tujuan program wajib diisi.',
            'kegiatan.required' => 'Minimal 1 kegiatan wajib diisi.',
            'kegiatan.min' => 'Minimal 1 kegiatan wajib diisi.',
            'kegiatan.*.nama_kegiatan.required' => 'Nama kegiatan wajib diisi.',
            'kegiatan.*.bulan.required' => 'Bulan kegiatan wajib dipilih.',
            'kegiatan.*.anggaran.required' => 'Minimal 1 anggaran per kegiatan wajib diisi.',
            'kegiatan.*.anggaran.min' => 'Minimal 1 anggaran per kegiatan wajib diisi.',
            'kegiatan.*.anggaran.*.kode_bidang.required' => 'Bidang pelayanan wajib dipilih.',
            'kegiatan.*.anggaran.*.kode_sub_bidang.required' => 'Sub bidang pelayanan wajib dipilih.',
            'kegiatan.*.anggaran.*.kode_jenis.required' => 'Jenis program wajib dipilih.',
            'kegiatan.*.anggaran.*.mata_anggaran.required' => 'Mata anggaran wajib diisi.',
            'kegiatan.*.anggaran.*.nominal_anggaran.required' => 'Nominal anggaran wajib diisi.',
            'kegiatan.*.kuisioner.*.pertanyaan.required' => 'Pertanyaan kuisioner wajib diisi.',
            'kegiatan.*.kuisioner.*.tipe.required' => 'Tipe kuisioner wajib dipilih.',
        ];
    }
}
