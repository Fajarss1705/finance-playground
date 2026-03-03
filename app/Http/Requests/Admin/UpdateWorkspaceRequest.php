<?php

namespace App\Http\Requests\Admin;

use App\Concerns\WorkspaceValidationRules;
use Illuminate\Foundation\Http\FormRequest;

class UpdateWorkspaceRequest extends FormRequest
{
    use WorkspaceValidationRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->workspaceRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->workspaceMessages();
    }
}
