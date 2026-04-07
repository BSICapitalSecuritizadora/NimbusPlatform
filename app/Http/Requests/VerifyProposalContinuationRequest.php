<?php

namespace App\Http\Requests;

use App\DTOs\Proposals\VerifyProposalContinuationDTO;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

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
            'cnpj.required' => 'Informe o CNPJ vinculado à proposta.',
            'cnpj.regex' => 'Informe um CNPJ válido no formato 00.000.000/0000-00.',
            'code.required' => 'Informe o código de acesso recebido por e-mail.',
            'code.digits' => "O código de acesso deve conter exatamente {$codeLength} dígitos.",
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => Str::digitsOnly((string) $this->input('code')),
        ]);
    }

    public function toDTO(): VerifyProposalContinuationDTO
    {
        return VerifyProposalContinuationDTO::fromArray($this->validated());
    }
}
