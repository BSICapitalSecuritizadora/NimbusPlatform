<?php

namespace App\Http\Requests\Nimbus;

use App\DTOs\Nimbus\SubmissionReplyDTO;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreSubmissionReplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth('nimbus')->check();
    }

    public function rules(): array
    {
        return [
            'comment' => ['nullable', 'string', 'max:5000'],
            'file' => ['nullable', 'file', 'mimes:pdf,doc,docx,xls,xlsx,zip,jpg,jpeg,png', 'max:30720'],
        ];
    }

    public function messages(): array
    {
        return [
            'comment.max' => 'O comentário pode ter no máximo 5.000 caracteres.',
            'file.file' => 'Selecione um arquivo válido para enviar.',
            'file.mimes' => 'Envie um arquivo PDF, DOC, DOCX, XLS, XLSX, ZIP ou imagem.',
            'file.max' => 'O arquivo corrigido pode ter no máximo 30 MB.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $comment = trim((string) $this->input('comment'));

            if (($comment === '') && (! $this->hasFile('file'))) {
                $validator->errors()->add('comment', 'Informe uma resposta ou anexe um arquivo corrigido.');
            }
        });
    }

    public function toDTO(): SubmissionReplyDTO
    {
        return SubmissionReplyDTO::fromArray($this->validated());
    }
}
