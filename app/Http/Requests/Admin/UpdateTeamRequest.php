<?php

namespace App\Http\Requests\Admin;

use App\Concerns\TeamValidationRules;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTeamRequest extends FormRequest
{
    use TeamValidationRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->teamRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->teamMessages();
    }
}
