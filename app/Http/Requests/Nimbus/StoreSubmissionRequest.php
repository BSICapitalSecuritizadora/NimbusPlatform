<?php

namespace App\Http\Requests\Nimbus;

use App\DTOs\Nimbus\StoreSubmissionDTO;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth('nimbus')->check();
    }

    /**
     * @return array<string, string|array<string>>
     */
    public function rules(): array
    {
        return [
            'responsible_name' => ['required', 'string', 'max:190'],
            'company_cnpj' => ['required', 'string', 'max:18'],
            'company_name' => ['required', 'string', 'max:190'],
            'main_activity' => ['nullable', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'website' => ['nullable', 'url', 'max:255'],
            'net_worth' => ['required', 'string'],
            'annual_revenue' => ['required', 'string'],
            'registrant_name' => ['required', 'string', 'max:190'],
            'registrant_position' => ['nullable', 'string', 'max:100'],
            'registrant_rg' => ['nullable', 'string', 'max:20'],
            'registrant_cpf' => ['required', 'string', 'max:14'],
            'shareholders' => ['required', 'json'],
            'is_us_person' => ['nullable', 'boolean'],
            'is_pep' => ['nullable', 'boolean'],
            'ultimo_balanco' => ['required', 'file', 'mimes:pdf', 'extensions:pdf', 'max:10240'],
            'dre' => ['required', 'file', 'mimes:pdf', 'extensions:pdf', 'max:10240'],
            'politicas' => ['required', 'file', 'mimes:pdf', 'extensions:pdf', 'max:10240'],
            'cartao_cnpj' => ['required', 'file', 'mimes:pdf', 'extensions:pdf', 'max:10240'],
            'procuracao' => ['required', 'file', 'mimes:pdf', 'extensions:pdf', 'max:10240'],
            'ata' => ['required', 'file', 'mimes:pdf', 'extensions:pdf', 'max:10240'],
            'contrato_social' => ['required', 'file', 'mimes:pdf', 'extensions:pdf', 'max:10240'],
            'estatuto' => ['required', 'file', 'mimes:pdf', 'extensions:pdf', 'max:10240'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'responsible_name.required' => 'Informe o nome do responsavel.',
            'company_cnpj.required' => 'Informe o CNPJ da empresa.',
            'company_name.required' => 'Informe a razao social da empresa.',
            'phone.required' => 'Informe o telefone de contato.',
            'net_worth.required' => 'Informe o patrimonio liquido.',
            'annual_revenue.required' => 'Informe o faturamento anual.',
            'registrant_name.required' => 'Informe o nome do cadastrante.',
            'registrant_cpf.required' => 'Informe o CPF do cadastrante.',
            'shareholders.required' => 'Informe a composição societária.',
            'shareholders.json' => 'Os dados dos sócios precisam estar em um formato válido.',
            'ultimo_balanco.required' => 'Anexe o ultimo balanco patrimonial.',
            'dre.required' => 'Anexe a DRE.',
            'politicas.required' => 'Anexe as politicas da empresa.',
            'cartao_cnpj.required' => 'Anexe o cartao CNPJ.',
            'procuracao.required' => 'Anexe a procuracao.',
            'ata.required' => 'Anexe a ata.',
            'contrato_social.required' => 'Anexe o contrato social.',
            'estatuto.required' => 'Anexe o estatuto social.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $shareholders = $this->decodedShareholders();

            if ($shareholders === []) {
                $validator->errors()->add('shareholders', 'Informe ao menos um sócio na composição societária.');

                return;
            }

            $totalPercentage = array_sum(array_map(
                static fn (array $shareholder): float => (float) ($shareholder['percentage'] ?? 0),
                $shareholders,
            ));

            if (abs($totalPercentage - 100.0) > 0.01) {
                $validator->errors()->add('shareholders', 'A soma da participação dos sócios deve ser exatamente 100%.');
            }
        });
    }

    public function toDTO(): StoreSubmissionDTO
    {
        return StoreSubmissionDTO::fromArray([
            ...$this->validated(),
            'ip' => $this->ip(),
            'user_agent' => $this->userAgent(),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function decodedShareholders(): array
    {
        $shareholders = json_decode((string) $this->input('shareholders'), true);

        if (! is_array($shareholders)) {
            return [];
        }

        return array_values(array_filter($shareholders, is_array(...)));
    }
}
