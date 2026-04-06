<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAdminSubmissionResponseFilesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'response_files' => ['required', 'array', 'min:1'],
            'response_files.*' => ['required', 'file', 'mimes:pdf,doc,docx,xls,xlsx,zip,jpg,jpeg,png,webp,gif', 'max:51200'],
            'visible_to_user' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'response_files.required' => 'Selecione ao menos um arquivo para anexar como resposta.',
            'response_files.array' => 'Os arquivos de resposta precisam ser enviados em lote.',
            'response_files.min' => 'Selecione ao menos um arquivo para anexar como resposta.',
            'response_files.*.required' => 'Um dos arquivos enviados está inválido.',
            'response_files.*.file' => 'Um dos itens enviados não é um arquivo válido.',
            'response_files.*.mimes' => 'Os arquivos de resposta devem estar nos formatos PDF, DOC, DOCX, XLS, XLSX, ZIP ou imagem.',
            'response_files.*.max' => 'Cada arquivo de resposta pode ter no máximo 50 MB.',
            'visible_to_user.boolean' => 'A visibilidade do arquivo precisa ser válida.',
        ];
    }
}
