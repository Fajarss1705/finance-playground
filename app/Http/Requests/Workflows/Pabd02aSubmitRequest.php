<?php

namespace App\Http\Requests\Workflows;

use Illuminate\Foundation\Http\FormRequest;

class Pabd02aSubmitRequest extends FormRequest
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
            'items.*.tipe_perubahan' => ['required', 'string', 'in:tarik_maju,proposal_baru'],
            'items.*.komentar' => ['required', 'string', 'min:10'],

            // Tarik Maju fields
            'items.*.pk04_anggaran_id' => ['nullable', 'required_if:items.*.tipe_perubahan,tarik_maju', 'integer', 'exists:pk04_anggaran,id'],
            'items.*.bulan_awal' => ['nullable', 'required_if:items.*.tipe_perubahan,tarik_maju', 'integer', 'min:1', 'max:12'],
            'items.*.bulan_tujuan' => ['nullable', 'required_if:items.*.tipe_perubahan,tarik_maju', 'integer', 'min:1', 'max:12'],

            // Per-item files (required for proposal_baru, optional for tarik_maju)
            'items.*.item_files' => ['nullable', 'array'],
            'items.*.item_files.*' => ['file', 'max:25600'],

            // Proposal Baru fields
            'items.*.proposal' => ['nullable', 'required_if:items.*.tipe_perubahan,proposal_baru', 'array'],
            'items.*.proposal.kode_kategori' => ['nullable', 'required_if:items.*.tipe_perubahan,proposal_baru', 'string'],
            'items.*.proposal.nama_program' => ['nullable', 'required_if:items.*.tipe_perubahan,proposal_baru', 'string', 'max:255'],
            'items.*.proposal.deskripsi_program' => ['nullable', 'required_if:items.*.tipe_perubahan,proposal_baru', 'string'],
            'items.*.proposal.tujuan_program' => ['nullable', 'required_if:items.*.tipe_perubahan,proposal_baru', 'string'],
            'items.*.proposal.kegiatan' => ['nullable', 'required_if:items.*.tipe_perubahan,proposal_baru', 'array', 'min:1'],
            'items.*.proposal.kegiatan.*.nama_kegiatan' => ['required', 'string', 'max:255'],
            'items.*.proposal.kegiatan.*.bulan' => ['required', 'integer', 'min:1', 'max:12'],
            'items.*.proposal.kegiatan.*.anggaran' => ['required', 'array', 'min:1'],
            'items.*.proposal.kegiatan.*.anggaran.*.kode_bidang' => ['required', 'string'],
            'items.*.proposal.kegiatan.*.anggaran.*.kode_sub_bidang' => ['required', 'string'],
            'items.*.proposal.kegiatan.*.anggaran.*.kode_jenis' => ['required', 'string'],
            'items.*.proposal.kegiatan.*.anggaran.*.mata_anggaran' => ['required', 'string'],
            'items.*.proposal.kegiatan.*.anggaran.*.deskripsi_pk' => ['nullable', 'string'],
            'items.*.proposal.kegiatan.*.anggaran.*.nominal_anggaran' => ['required', 'numeric', 'min:0'],
            'items.*.proposal.kegiatan.*.kuisioner' => ['required', 'array', 'min:1'],
            'items.*.proposal.kegiatan.*.kuisioner.*.pertanyaan' => ['required', 'string'],
            'items.*.proposal.kegiatan.*.kuisioner.*.tipe' => ['required', 'string'],
            'items.*.proposal.kegiatan.*.kuisioner.*.satuan' => ['nullable', 'string'],
            'items.*.proposal.kegiatan.*.kuisioner.*.kode_kuisioner' => ['nullable', 'string'],

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
            'items.required' => 'Minimal 1 item perubahan harus ditambahkan.',
            'items.min' => 'Minimal 1 item perubahan harus ditambahkan.',
            'items.*.tipe_perubahan.required' => 'Tipe perubahan harus dipilih.',
            'items.*.tipe_perubahan.in' => 'Tipe perubahan tidak valid.',
            'items.*.komentar.required' => 'Alasan perubahan harus diisi.',
            'items.*.komentar.min' => 'Alasan perubahan minimal 10 karakter.',
            'items.*.pk04_anggaran_id.required_if' => 'Anggaran sumber harus dipilih untuk tarik maju.',
            'items.*.pk04_anggaran_id.exists' => 'Anggaran sumber tidak ditemukan.',
            'items.*.bulan_awal.required_if' => 'Bulan asal harus diisi.',
            'items.*.bulan_tujuan.required_if' => 'Bulan tujuan harus dipilih.',
            'items.*.proposal.required_if' => 'Data proposal harus diisi.',
            'items.*.proposal.nama_program.required_if' => 'Nama program harus diisi.',
            'items.*.proposal.kegiatan.required_if' => 'Minimal 1 kegiatan harus ditambahkan.',
            'items.*.proposal.kegiatan.min' => 'Minimal 1 kegiatan harus ditambahkan.',
            'items.*.proposal.kegiatan.*.nama_kegiatan.required' => 'Nama kegiatan harus diisi.',
            'items.*.proposal.kegiatan.*.bulan.required' => 'Bulan kegiatan harus dipilih.',
            'items.*.proposal.kegiatan.*.anggaran.required' => 'Minimal 1 anggaran per kegiatan.',
            'items.*.proposal.kegiatan.*.anggaran.min' => 'Minimal 1 anggaran per kegiatan.',
            'items.*.proposal.kegiatan.*.kuisioner.required' => 'Minimal 1 kuisioner per kegiatan.',
            'items.*.proposal.kegiatan.*.kuisioner.min' => 'Minimal 1 kuisioner per kegiatan.',
        ];
    }
}
