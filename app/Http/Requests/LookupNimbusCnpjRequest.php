<?php

namespace App\Http\Requests;

use App\DTOs\Nimbus\LookupNimbusCnpjDTO;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class LookupNimbusCnpjRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cnpj' => ['required', 'digits:14'],
        ];
    }

    public function messages(): array
    {
        return [
            'cnpj.required' => 'Informe o CNPJ para realizar a consulta.',
            'cnpj.digits' => 'Informe um CNPJ valido com 14 digitos.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'cnpj' => Str::digitsOnly((string) $this->input('cnpj')),
        ]);
    }

    public function toDTO(): LookupNimbusCnpjDTO
    {
        return LookupNimbusCnpjDTO::fromArray($this->validated());
    }
}
