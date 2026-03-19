<?php

namespace App\Http\Requests\Workflows;

use Illuminate\Foundation\Http\FormRequest;

class Pabd02aDraftRequest extends FormRequest
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
            'items.*.tipe_perubahan' => ['nullable', 'string', 'in:tarik_maju,proposal_baru'],
            'items.*.pk04_anggaran_id' => ['nullable', 'integer'],
            'items.*.bulan_awal' => ['nullable', 'integer', 'min:1', 'max:12'],
            'items.*.bulan_tujuan' => ['nullable', 'integer', 'min:1', 'max:12'],
            'items.*.komentar' => ['nullable', 'string'],
            'items.*.item_files' => ['nullable', 'array'],
            'items.*.item_files.*' => ['file', 'max:25600'],
            'items.*.proposal' => ['nullable', 'array'],
            'items.*.proposal.kode_kategori' => ['nullable', 'string'],
            'items.*.proposal.nama_program' => ['nullable', 'string', 'max:255'],
            'items.*.proposal.deskripsi_program' => ['nullable', 'string'],
            'items.*.proposal.tujuan_program' => ['nullable', 'string'],
            'items.*.proposal.kegiatan' => ['nullable', 'array'],
            'items.*.proposal.kegiatan.*.nama_kegiatan' => ['nullable', 'string', 'max:255'],
            'items.*.proposal.kegiatan.*.bulan' => ['nullable', 'integer', 'min:1', 'max:12'],
            'items.*.proposal.kegiatan.*.anggaran' => ['nullable', 'array'],
            'items.*.proposal.kegiatan.*.anggaran.*.kode_bidang' => ['nullable', 'string'],
            'items.*.proposal.kegiatan.*.anggaran.*.kode_sub_bidang' => ['nullable', 'string'],
            'items.*.proposal.kegiatan.*.anggaran.*.kode_jenis' => ['nullable', 'string'],
            'items.*.proposal.kegiatan.*.anggaran.*.mata_anggaran' => ['nullable', 'string'],
            'items.*.proposal.kegiatan.*.anggaran.*.deskripsi_pk' => ['nullable', 'string'],
            'items.*.proposal.kegiatan.*.anggaran.*.nominal_anggaran' => ['nullable', 'numeric', 'min:0'],
            'items.*.proposal.kegiatan.*.kuisioner' => ['nullable', 'array'],
            'items.*.proposal.kegiatan.*.kuisioner.*.kode_kuisioner' => ['nullable', 'string'],
            'items.*.proposal.kegiatan.*.kuisioner.*.pertanyaan' => ['nullable', 'string'],
            'items.*.proposal.kegiatan.*.kuisioner.*.tipe' => ['nullable', 'string'],
            'items.*.proposal.kegiatan.*.kuisioner.*.satuan' => ['nullable', 'string'],
            'expected_updated_at' => ['required', 'string'],
            'notes' => ['nullable', 'string'],
            'files' => ['nullable', 'array'],
            'files.*' => ['file', 'max:51200'],
        ];
    }
}
