<?php

namespace App\Http\Requests\Admin\Workflows;

use Illuminate\Foundation\Http\FormRequest;

class Pp07SubmitRequest extends FormRequest
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
            'draft_data.tahun' => ['required', 'integer', 'min:2020', 'max:2099'],
            'draft_data.tanggal_mulai_pra_raker' => ['required', 'date'],
            'draft_data.tanggal_penetapan_program' => ['required', 'date', 'after:draft_data.tanggal_mulai_pra_raker'],
            'draft_data.kode_bidang_pelayanan' => ['required', 'array', 'min:1'],
            'draft_data.kode_bidang_pelayanan.*.kode' => ['required', 'string', 'max:10'],
            'draft_data.kode_bidang_pelayanan.*.nama' => ['required', 'string', 'max:255'],
            'draft_data.kode_bidang_pelayanan.*.catatan' => ['nullable', 'string'],
            'draft_data.kode_sub_bidang_pelayanan' => ['required', 'array', 'min:1'],
            'draft_data.kode_sub_bidang_pelayanan.*.kode' => ['required', 'string', 'max:10'],
            'draft_data.kode_sub_bidang_pelayanan.*.nama' => ['required', 'string', 'max:255'],
            'draft_data.kode_sub_bidang_pelayanan.*.catatan' => ['nullable', 'string'],
            'draft_data.kode_kategori_pelayanan' => ['required', 'array', 'min:1'],
            'draft_data.kode_kategori_pelayanan.*.kode' => ['required', 'string', 'max:10'],
            'draft_data.kode_kategori_pelayanan.*.nama' => ['required', 'string', 'max:255'],
            'draft_data.kode_kategori_pelayanan.*.catatan' => ['nullable', 'string'],
            'draft_data.kode_jenis_program' => ['required', 'array', 'min:1'],
            'draft_data.kode_jenis_program.*.kode' => ['required', 'string', 'max:10'],
            'draft_data.kode_jenis_program.*.nama' => ['required', 'string', 'max:255'],
            'draft_data.kode_jenis_program.*.catatan' => ['nullable', 'string'],
            'draft_data.item_kuisioner' => ['required', 'array', 'min:1'],
            'draft_data.item_kuisioner.*.kode' => ['required', 'string', 'max:10'],
            'draft_data.item_kuisioner.*.pertanyaan' => ['required', 'string', 'max:255'],
            'draft_data.item_kuisioner.*.tipe' => ['required', 'string', 'max:50'],
            'draft_data.item_kuisioner.*.satuan' => ['required_if:draft_data.item_kuisioner.*.tipe,Kuantitatif', 'nullable', 'string', 'max:100'],
            'draft_data.item_plafon_anggaran' => ['required', 'array', 'min:1'],
            'draft_data.item_plafon_anggaran.*.team_id' => ['required', 'integer', 'exists:teams,id'],
            'draft_data.item_plafon_anggaran.*.kode_team' => ['required', 'string', 'max:10'],
            'draft_data.item_plafon_anggaran.*.plafon_anggaran' => ['required', 'numeric', 'min:0'],
            'draft_data.item_plafon_anggaran.*.nama_bank' => ['required', 'string', 'max:255'],
            'draft_data.item_plafon_anggaran.*.nama_rekening' => ['required', 'string', 'max:255'],
            'draft_data.item_plafon_anggaran.*.nomor_rekening' => ['required', 'string', 'max:50'],
            'draft_data.item_plafon_anggaran.*.catatan' => ['nullable', 'string'],
            'draft_data.item_dokumen_sop' => ['nullable', 'array'],
            'draft_data.item_dokumen_sop.*.file_id' => ['required', 'integer', 'exists:files,id'],
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
            'draft_data.tahun.required' => 'Tahun periode wajib diisi.',
            'draft_data.tanggal_mulai_pra_raker.required' => 'Tanggal mulai pra-raker wajib diisi.',
            'draft_data.tanggal_penetapan_program.required' => 'Tanggal penetapan program wajib diisi.',
            'draft_data.tanggal_penetapan_program.after' => 'Tanggal penetapan program harus setelah tanggal mulai pra-raker.',
            'draft_data.kode_bidang_pelayanan.required' => 'Minimal 1 kode bidang pelayanan wajib diisi.',
            'draft_data.kode_bidang_pelayanan.min' => 'Minimal 1 kode bidang pelayanan wajib diisi.',
            'draft_data.kode_sub_bidang_pelayanan.required' => 'Minimal 1 kode sub bidang pelayanan wajib diisi.',
            'draft_data.kode_sub_bidang_pelayanan.min' => 'Minimal 1 kode sub bidang pelayanan wajib diisi.',
            'draft_data.kode_kategori_pelayanan.required' => 'Minimal 1 kode kategori pelayanan wajib diisi.',
            'draft_data.kode_kategori_pelayanan.min' => 'Minimal 1 kode kategori pelayanan wajib diisi.',
            'draft_data.kode_jenis_program.required' => 'Minimal 1 kode jenis program wajib diisi.',
            'draft_data.kode_jenis_program.min' => 'Minimal 1 kode jenis program wajib diisi.',
            'draft_data.item_kuisioner.required' => 'Minimal 1 pertanyaan kuisioner wajib diisi.',
            'draft_data.item_kuisioner.min' => 'Minimal 1 pertanyaan kuisioner wajib diisi.',
            'draft_data.item_kuisioner.*.kode.required' => 'Kode wajib diisi.',
            'draft_data.item_kuisioner.*.pertanyaan.required' => 'Pertanyaan wajib diisi.',
            'draft_data.item_kuisioner.*.tipe.required' => 'Tipe wajib diisi.',
            'draft_data.item_kuisioner.*.satuan.required_if' => 'Satuan wajib diisi untuk tipe Kuantitatif.',
            'draft_data.item_plafon_anggaran.required' => 'Minimal 1 item plafon anggaran wajib diisi.',
            'draft_data.item_plafon_anggaran.min' => 'Minimal 1 item plafon anggaran wajib diisi.',
            'draft_data.item_plafon_anggaran.*.team_id.required' => 'Tim wajib dipilih.',
            'draft_data.item_plafon_anggaran.*.team_id.exists' => 'Tim tidak ditemukan.',
            'draft_data.item_plafon_anggaran.*.kode_team.required' => 'Kode tim wajib diisi.',
            'draft_data.item_plafon_anggaran.*.plafon_anggaran.required' => 'Plafon anggaran wajib diisi.',
            'draft_data.item_plafon_anggaran.*.plafon_anggaran.min' => 'Plafon anggaran tidak boleh negatif.',
            'draft_data.item_plafon_anggaran.*.nama_bank.required' => 'Nama bank wajib diisi.',
            'draft_data.item_plafon_anggaran.*.nama_rekening.required' => 'Nama rekening wajib diisi.',
            'draft_data.item_plafon_anggaran.*.nomor_rekening.required' => 'Nomor rekening wajib diisi.',
        ];
    }
}
