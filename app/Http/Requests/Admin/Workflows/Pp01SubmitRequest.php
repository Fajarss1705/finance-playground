<?php

namespace App\Http\Requests\Admin\Workflows;

use Illuminate\Foundation\Http\FormRequest;

class Pp01SubmitRequest extends FormRequest
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
            'tahun' => ['required', 'integer', 'min:2020', 'max:2099'],
            'tanggal_mulai_pra_raker' => ['required', 'date'],
            'tanggal_penetapan_program' => ['required', 'date', 'after:tanggal_mulai_pra_raker'],
            'kode_bidang_pelayanan' => ['required', 'array', 'min:1'],
            'kode_bidang_pelayanan.*.kode' => ['required', 'string', 'max:10'],
            'kode_bidang_pelayanan.*.nama' => ['required', 'string', 'max:255'],
            'kode_bidang_pelayanan.*.catatan' => ['nullable', 'string'],
            'kode_sub_bidang_pelayanan' => ['required', 'array', 'min:1'],
            'kode_sub_bidang_pelayanan.*.kode' => ['required', 'string', 'max:10'],
            'kode_sub_bidang_pelayanan.*.nama' => ['required', 'string', 'max:255'],
            'kode_sub_bidang_pelayanan.*.catatan' => ['nullable', 'string'],
            'kode_kategori_pelayanan' => ['required', 'array', 'min:1'],
            'kode_kategori_pelayanan.*.kode' => ['required', 'string', 'max:10'],
            'kode_kategori_pelayanan.*.nama' => ['required', 'string', 'max:255'],
            'kode_kategori_pelayanan.*.catatan' => ['nullable', 'string'],
            'kode_jenis_program' => ['required', 'array', 'min:1'],
            'kode_jenis_program.*.kode' => ['required', 'string', 'max:10'],
            'kode_jenis_program.*.nama' => ['required', 'string', 'max:255'],
            'kode_jenis_program.*.catatan' => ['nullable', 'string'],
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
            'tahun.required' => 'Tahun periode wajib diisi.',
            'tanggal_mulai_pra_raker.required' => 'Tanggal mulai pra-raker wajib diisi.',
            'tanggal_penetapan_program.required' => 'Tanggal penetapan program wajib diisi.',
            'tanggal_penetapan_program.after' => 'Tanggal penetapan program harus setelah tanggal mulai pra-raker.',
            'kode_bidang_pelayanan.required' => 'Minimal 1 kode bidang pelayanan wajib diisi.',
            'kode_bidang_pelayanan.min' => 'Minimal 1 kode bidang pelayanan wajib diisi.',
            'kode_sub_bidang_pelayanan.required' => 'Minimal 1 kode sub bidang pelayanan wajib diisi.',
            'kode_sub_bidang_pelayanan.min' => 'Minimal 1 kode sub bidang pelayanan wajib diisi.',
            'kode_kategori_pelayanan.required' => 'Minimal 1 kode kategori pelayanan wajib diisi.',
            'kode_kategori_pelayanan.min' => 'Minimal 1 kode kategori pelayanan wajib diisi.',
            'kode_jenis_program.required' => 'Minimal 1 kode jenis program wajib diisi.',
            'kode_jenis_program.min' => 'Minimal 1 kode jenis program wajib diisi.',
        ];
    }
}
