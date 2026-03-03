<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAdminFileRequest extends FormRequest
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
            'file' => [
                'required',
                'file',
                'max:51200', // 50MB
                'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,zip',
            ],
            'source_route' => ['nullable', 'string', 'max:255'],
            'is_workspace_public' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'File wajib diunggah.',
            'file.file' => 'Data yang diunggah harus berupa file.',
            'file.max' => 'Ukuran file maksimal 50MB.',
            'file.mimes' => 'Tipe file harus: PDF, Word, Excel, PowerPoint, JPG, PNG, atau ZIP.',
        ];
    }
}
