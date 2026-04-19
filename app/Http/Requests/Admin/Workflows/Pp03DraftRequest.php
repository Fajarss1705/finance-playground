<?php

namespace App\Http\Requests\Admin\Workflows;

use Illuminate\Foundation\Http\FormRequest;

class Pp03DraftRequest extends FormRequest
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
            'item_plafon_anggaran' => ['nullable', 'array'],
            'item_plafon_anggaran.*.team_id' => ['nullable', 'integer', 'exists:teams,id'],
            'item_plafon_anggaran.*.kode_team' => ['nullable', 'string', 'max:10'],
            'item_plafon_anggaran.*.plafon_anggaran' => ['nullable', 'numeric', 'min:0'],
            'item_plafon_anggaran.*.nama_bank' => ['nullable', 'string', 'max:255'],
            'item_plafon_anggaran.*.nama_rekening' => ['nullable', 'string', 'max:255'],
            'item_plafon_anggaran.*.nomor_rekening' => ['nullable', 'string', 'max:50'],
            'item_plafon_anggaran.*.catatan' => ['nullable', 'string'],
            'rekening_organisasi' => ['nullable', 'array'],
            'rekening_organisasi.*.nama_bank' => ['nullable', 'string', 'max:255'],
            'rekening_organisasi.*.nama_rekening' => ['nullable', 'string', 'max:255'],
            'rekening_organisasi.*.nomor_rekening' => ['nullable', 'string', 'max:50'],
            'rekening_organisasi.*.catatan' => ['nullable', 'string'],
            'expected_updated_at' => ['required', 'string'],
            'notes' => ['nullable', 'string'],
            'files' => ['nullable', 'array'],
            'files.*' => ['file', 'max:51200'],
        ];
    }
}
