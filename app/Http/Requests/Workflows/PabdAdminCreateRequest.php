<?php

namespace App\Http\Requests\Workflows;

use Illuminate\Foundation\Http\FormRequest;

class PabdAdminCreateRequest extends FormRequest
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
            'team_id' => ['required', 'integer', 'exists:teams,id'],
            'bulan' => ['required', 'integer', 'min:1', 'max:12'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
