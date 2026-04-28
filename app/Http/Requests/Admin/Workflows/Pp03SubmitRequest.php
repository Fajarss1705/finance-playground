<?php

namespace App\Http\Requests\Admin\Workflows;

use Illuminate\Foundation\Http\FormRequest;

class Pp03SubmitRequest extends FormRequest
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
            'item_plafon_anggaran' => ['required', 'array', 'min:1'],
            'item_plafon_anggaran.*.team_id' => ['required', 'integer', 'exists:teams,id'],
            'item_plafon_anggaran.*.kode_team' => ['required', 'string', 'max:10'],
            'item_plafon_anggaran.*.plafon_anggaran' => ['required', 'numeric', 'min:0'],
            'item_plafon_anggaran.*.nama_bank' => ['required', 'string', 'max:255'],
            'item_plafon_anggaran.*.nama_rekening' => ['required', 'string', 'max:255'],
            'item_plafon_anggaran.*.nomor_rekening' => ['required', 'string', 'max:50'],
            'item_plafon_anggaran.*.catatan' => ['nullable', 'string'],
            'rekening_organisasi' => ['required', 'array', 'min:1'],
            'rekening_organisasi.*.nama_bank' => ['required', 'string', 'max:255'],
            'rekening_organisasi.*.nama_rekening' => ['required', 'string', 'max:255'],
            'rekening_organisasi.*.nomor_rekening' => ['required', 'string', 'max:50'],
            'rekening_organisasi.*.catatan' => ['nullable', 'string'],
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
            'item_plafon_anggaran.required' => 'Minimal 1 item plafon anggaran wajib diisi.',
            'item_plafon_anggaran.min' => 'Minimal 1 item plafon anggaran wajib diisi.',
            'item_plafon_anggaran.*.team_id.required' => 'Tim wajib dipilih.',
            'item_plafon_anggaran.*.team_id.exists' => 'Tim tidak ditemukan.',
            'item_plafon_anggaran.*.kode_team.required' => 'Kode tim wajib diisi.',
            'item_plafon_anggaran.*.plafon_anggaran.required' => 'Plafon anggaran wajib diisi.',
            'item_plafon_anggaran.*.plafon_anggaran.min' => 'Plafon anggaran tidak boleh negatif.',
            'item_plafon_anggaran.*.nama_bank.required' => 'Nama bank wajib diisi.',
            'item_plafon_anggaran.*.nama_rekening.required' => 'Nama rekening wajib diisi.',
            'item_plafon_anggaran.*.nomor_rekening.required' => 'Nomor rekening wajib diisi.',
            'rekening_organisasi.required' => 'Minimal 1 rekening organisasi wajib diisi.',
            'rekening_organisasi.min' => 'Minimal 1 rekening organisasi wajib diisi.',
            'rekening_organisasi.*.nama_bank.required' => 'Nama bank rekening organisasi wajib diisi.',
            'rekening_organisasi.*.nama_rekening.required' => 'Nama rekening organisasi wajib diisi.',
            'rekening_organisasi.*.nomor_rekening.required' => 'Nomor rekening organisasi wajib diisi.',
        ];
    }
}
