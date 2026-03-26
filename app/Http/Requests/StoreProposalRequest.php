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
            'cnpj' => ['required', 'string', 'max:18'],
            'nome_empresa' => ['required', 'string', 'max:255'],
            'ie' => ['nullable', 'string', 'max:255'],
            'site' => ['nullable', 'url', 'max:255'],
            'setores' => ['required', 'array', 'min:1'],
            'setores.*' => ['exists:proposal_sectors,id'],
            'cep' => ['required', 'string', 'max:9'],
            'logradouro' => ['required', 'string', 'max:255'],
            'numero' => ['required', 'string', 'max:255'],
            'complemento' => ['nullable', 'string', 'max:255'],
            'bairro' => ['required', 'string', 'max:255'],
            'cidade' => ['required', 'string', 'max:255'],
            'estado' => ['required', 'string', 'size:2'],
            'nome_contato' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'telefone_pessoal' => ['required', 'string', 'max:20'],
            'whatsapp' => ['nullable', 'boolean'],
            'telefone_empresa' => ['nullable', 'string', 'max:20'],
            'cargo' => ['nullable', 'string', 'max:255'],
            'observacoes' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'setores.required' => 'Selecione ao menos um setor de atuação.',
            'nome_empresa.required' => 'O nome da empresa é obrigatório.',
            'nome_contato.required' => 'O nome do contato é obrigatório.',
            'email.required' => 'O e-mail do contato é obrigatório.',
            'email.email' => 'Informe um e-mail válido.',
            'telefone_pessoal.required' => 'O telefone pessoal é obrigatório.',
        ];
    }
}
