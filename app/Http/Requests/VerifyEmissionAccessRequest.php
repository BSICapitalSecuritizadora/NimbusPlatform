<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class VerifyEmissionAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $codeLength = max(4, (int) config('emissions.access.code_length', 6));

        return [
            'code' => ['required', 'digits:'.$codeLength],
        ];
    }

    public function messages(): array
    {
        $codeLength = max(4, (int) config('emissions.access.code_length', 6));

        return [
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
}
