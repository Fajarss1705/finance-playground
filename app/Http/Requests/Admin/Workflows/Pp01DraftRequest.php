<?php

namespace App\Http\Requests\Admin\Workflows;

use Illuminate\Foundation\Http\FormRequest;

class Pp01DraftRequest extends FormRequest
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
            'tahun' => ['nullable', 'integer', 'min:2020', 'max:2099'],
            'tanggal_mulai_pra_raker' => ['nullable', 'date'],
            'tanggal_penetapan_program' => ['nullable', 'date'],
            'kode_bidang_pelayanan' => ['nullable', 'array'],
            'kode_bidang_pelayanan.*.kode' => ['required', 'string', 'max:10'],
            'kode_bidang_pelayanan.*.nama' => ['required', 'string', 'max:255'],
            'kode_bidang_pelayanan.*.catatan' => ['nullable', 'string'],
            'kode_sub_bidang_pelayanan' => ['nullable', 'array'],
            'kode_sub_bidang_pelayanan.*.kode' => ['required', 'string', 'max:10'],
            'kode_sub_bidang_pelayanan.*.nama' => ['required', 'string', 'max:255'],
            'kode_sub_bidang_pelayanan.*.catatan' => ['nullable', 'string'],
            'kode_kategori_pelayanan' => ['nullable', 'array'],
            'kode_kategori_pelayanan.*.kode' => ['required', 'string', 'max:10'],
            'kode_kategori_pelayanan.*.nama' => ['required', 'string', 'max:255'],
            'kode_kategori_pelayanan.*.catatan' => ['nullable', 'string'],
            'kode_jenis_program' => ['nullable', 'array'],
            'kode_jenis_program.*.kode' => ['required', 'string', 'max:10'],
            'kode_jenis_program.*.nama' => ['required', 'string', 'max:255'],
            'kode_jenis_program.*.catatan' => ['nullable', 'string'],
            'expected_updated_at' => ['required', 'string'],
            'notes' => ['nullable', 'string'],
            'files' => ['nullable', 'array'],
            'files.*' => ['file', 'max:51200'],
        ];
    }
}
