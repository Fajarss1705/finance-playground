<?php

namespace App\Http\Requests\Admin\Workflows;

use Illuminate\Foundation\Http\FormRequest;

class Pp07DraftRequest extends FormRequest
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
            'draft_data.tahun' => ['nullable', 'integer', 'min:2020', 'max:2099'],
            'draft_data.tanggal_mulai_pra_raker' => ['nullable', 'date'],
            'draft_data.tanggal_penetapan_program' => ['nullable', 'date'],
            'draft_data.kode_bidang_pelayanan' => ['nullable', 'array'],
            'draft_data.kode_bidang_pelayanan.*.kode' => ['nullable', 'string', 'max:10'],
            'draft_data.kode_bidang_pelayanan.*.nama' => ['nullable', 'string', 'max:255'],
            'draft_data.kode_bidang_pelayanan.*.catatan' => ['nullable', 'string'],
            'draft_data.kode_sub_bidang_pelayanan' => ['nullable', 'array'],
            'draft_data.kode_sub_bidang_pelayanan.*.kode' => ['nullable', 'string', 'max:10'],
            'draft_data.kode_sub_bidang_pelayanan.*.nama' => ['nullable', 'string', 'max:255'],
            'draft_data.kode_sub_bidang_pelayanan.*.catatan' => ['nullable', 'string'],
            'draft_data.kode_kategori_pelayanan' => ['nullable', 'array'],
            'draft_data.kode_kategori_pelayanan.*.kode' => ['nullable', 'string', 'max:10'],
            'draft_data.kode_kategori_pelayanan.*.nama' => ['nullable', 'string', 'max:255'],
            'draft_data.kode_kategori_pelayanan.*.catatan' => ['nullable', 'string'],
            'draft_data.kode_jenis_program' => ['nullable', 'array'],
            'draft_data.kode_jenis_program.*.kode' => ['nullable', 'string', 'max:10'],
            'draft_data.kode_jenis_program.*.nama' => ['nullable', 'string', 'max:255'],
            'draft_data.kode_jenis_program.*.catatan' => ['nullable', 'string'],
            'draft_data.item_kuisioner' => ['nullable', 'array'],
            'draft_data.item_kuisioner.*.kode' => ['nullable', 'string', 'max:10'],
            'draft_data.item_kuisioner.*.pertanyaan' => ['nullable', 'string', 'max:255'],
            'draft_data.item_kuisioner.*.tipe' => ['nullable', 'string', 'max:50'],
            'draft_data.item_kuisioner.*.satuan' => ['nullable', 'string', 'max:100'],
            'draft_data.item_plafon_anggaran' => ['nullable', 'array'],
            'draft_data.item_plafon_anggaran.*.team_id' => ['nullable', 'integer', 'exists:teams,id'],
            'draft_data.item_plafon_anggaran.*.kode_team' => ['nullable', 'string', 'max:10'],
            'draft_data.item_plafon_anggaran.*.plafon_anggaran' => ['nullable', 'numeric', 'min:0'],
            'draft_data.item_plafon_anggaran.*.nama_bank' => ['nullable', 'string', 'max:255'],
            'draft_data.item_plafon_anggaran.*.nama_rekening' => ['nullable', 'string', 'max:255'],
            'draft_data.item_plafon_anggaran.*.nomor_rekening' => ['nullable', 'string', 'max:50'],
            'draft_data.item_plafon_anggaran.*.catatan' => ['nullable', 'string'],
            'draft_data.item_dokumen_sop' => ['nullable', 'array'],
            'draft_data.item_dokumen_sop.*.file_id' => ['required', 'integer', 'exists:files,id'],
            'keep_file_ids' => ['nullable', 'array'],
            'keep_file_ids.*' => ['integer', 'exists:files,id'],
            'dokumen_files' => ['nullable', 'array'],
            'dokumen_files.*' => ['file', 'max:51200'],
            'expected_updated_at' => ['required', 'string'],
            'notes' => ['nullable', 'string'],
            'files' => ['nullable', 'array'],
            'files.*' => ['file', 'max:51200'],
        ];
    }
}
