<?php

namespace App\Http\Requests\Admin;

use App\Concerns\OrganizationValidationRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreOrganizationRequest extends FormRequest
{
    use OrganizationValidationRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->organizationRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->organizationMessages();
    }
}
