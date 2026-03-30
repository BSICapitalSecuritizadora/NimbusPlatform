<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cnpj' => ['required', 'string', 'regex:/^\d{2}\.?\d{3}\.?\d{3}\/?\d{4}\-?\d{2}$/'],
            'nome_empresa' => ['required', 'string', 'max:255'],
            'ie' => ['nullable', 'string', 'max:255'],
            'site' => ['nullable', 'url', 'max:255'],
            'setores' => ['required', 'array', 'min:1'],
            'setores.*' => ['distinct', 'exists:proposal_sectors,id'],
            'cep' => ['required', 'string', 'regex:/^\d{5}\-?\d{3}$/'],
            'logradouro' => ['required', 'string', 'max:255'],
            'numero' => ['required', 'string', 'max:255'],
            'complemento' => ['nullable', 'string', 'max:255'],
            'bairro' => ['required', 'string', 'max:255'],
            'cidade' => ['required', 'string', 'max:255'],
            'estado' => ['required', 'string', 'size:2'],
            'nome_contato' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'telefone_pessoal' => ['required', 'string', 'regex:/^\(?\d{2}\)?\s?\d{4,5}\-?\d{4}$/'],
            'whatsapp' => ['nullable', 'boolean'],
            'telefone_empresa' => ['nullable', 'string', 'regex:/^\(?\d{2}\)?\s?\d{4,5}\-?\d{4}$/'],
            'cargo' => ['nullable', 'string', 'max:255'],
            'observacoes' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'cnpj.required' => 'O CNPJ da empresa é obrigatório.',
            'cnpj.regex' => 'Informe um CNPJ válido no formato 00.000.000/0000-00.',
            'cep.required' => 'O CEP é obrigatório.',
            'cep.regex' => 'Informe um CEP válido no formato 00000-000.',
            'setores.required' => 'Selecione ao menos um setor de atuação.',
            'nome_empresa.required' => 'A razão social da empresa é obrigatória.',
            'logradouro.required' => 'O logradouro é obrigatório.',
            'numero.required' => 'O número do endereço é obrigatório.',
            'bairro.required' => 'O bairro é obrigatório.',
            'cidade.required' => 'A cidade é obrigatória.',
            'estado.required' => 'O estado (UF) é obrigatório.',
            'nome_contato.required' => 'O nome do responsável pelo contato é obrigatório.',
            'email.required' => 'O e-mail de contato é obrigatório.',
            'email.email' => 'Informe um endereço de e-mail válido.',
            'telefone_pessoal.required' => 'O telefone pessoal do contato é obrigatório.',
            'telefone_pessoal.regex' => 'Informe um número de telefone pessoal válido.',
            'telefone_empresa.regex' => 'Informe um número de telefone da empresa válido.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'cnpj' => trim((string) $this->input('cnpj')),
            'cep' => trim((string) $this->input('cep')),
            'telefone_pessoal' => trim((string) $this->input('telefone_pessoal')),
            'telefone_empresa' => filled($this->input('telefone_empresa'))
                ? trim((string) $this->input('telefone_empresa'))
                : null,
        ]);
    }
}
