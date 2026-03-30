<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerifyProposalContinuationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $codeLength = max(4, (int) config('proposals.continuation_access.code_length', 6));

        return [
            'cnpj' => ['required', 'string', 'regex:/^\d{2}\.?\d{3}\.?\d{3}\/?\d{4}\-?\d{2}$/'],
            'code' => ['required', 'digits:'.$codeLength],
        ];
    }

    public function messages(): array
    {
        $codeLength = max(4, (int) config('proposals.continuation_access.code_length', 6));

        return [
            'cnpj.required' => 'Informe o CNPJ cadastrado na proposta.',
            'code.required' => 'Informe o código enviado por e-mail.',
            'cnpj.regex' => 'Informe um CNPJ válido.',
            'code.digits' => "O código deve conter {$codeLength} dígitos.",
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => preg_replace('/\D/', '', (string) $this->input('code')),
        ]);
    }
}
