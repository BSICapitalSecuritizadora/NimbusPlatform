<?php

namespace App\Http\Requests\Nimbus;

use App\DTOs\Nimbus\StoreSubmissionDTO;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreSubmissionRequest extends FormRequest
{
    private const DOCUMENT_FIELDS = [
        'ultimo_balanco',
        'dre',
        'politicas',
        'cartao_cnpj',
        'procuracao',
        'ata',
        'contrato_social',
        'estatuto',
    ];

    public function authorize(): bool
    {
        return auth('nimbus')->check();
    }

    /**
     * @return array<string, string|array<string>>
     */
    public function rules(): array
    {
        $submissionFileMaxKb = $this->submissionFileMaxKb();

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
            'is_anbima_affiliated' => ['required', 'boolean'],
            'ultimo_balanco' => ['required', 'file', 'mimes:pdf', 'extensions:pdf', 'max:'.$submissionFileMaxKb],
            'dre' => ['required', 'file', 'mimes:pdf', 'extensions:pdf', 'max:'.$submissionFileMaxKb],
            'politicas' => ['required', 'file', 'mimes:pdf', 'extensions:pdf', 'max:'.$submissionFileMaxKb],
            'cartao_cnpj' => ['required', 'file', 'mimes:pdf', 'extensions:pdf', 'max:'.$submissionFileMaxKb],
            'procuracao' => ['nullable', 'file', 'mimes:pdf', 'extensions:pdf', 'max:'.$submissionFileMaxKb],
            'ata' => ['nullable', 'file', 'mimes:pdf', 'extensions:pdf', 'max:'.$submissionFileMaxKb],
            'contrato_social' => ['required', 'file', 'mimes:pdf', 'extensions:pdf', 'max:'.$submissionFileMaxKb],
            'estatuto' => ['required', 'file', 'mimes:pdf', 'extensions:pdf', 'max:'.$submissionFileMaxKb],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'responsible_name.required' => 'Informe o nome do responsável.',
            'company_cnpj.required' => 'Informe o CNPJ da empresa.',
            'company_name.required' => 'Informe a razão social da empresa.',
            'phone.required' => 'Informe o telefone de contato.',
            'net_worth.required' => 'Informe o patrimônio líquido.',
            'annual_revenue.required' => 'Informe o faturamento anual.',
            'registrant_name.required' => 'Informe o nome do cadastrante.',
            'registrant_cpf.required' => 'Informe o CPF do cadastrante.',
            'shareholders.required' => 'Informe a composição societária.',
            'shareholders.json' => 'Os dados dos sócios precisam estar em um formato válido.',
            'is_anbima_affiliated.required' => 'Informe se você é filiado à Anbima.',
            'is_anbima_affiliated.boolean' => 'Selecione uma opção válida para a filiação à Anbima.',
            'ultimo_balanco.required' => 'Anexe o último balanço patrimonial.',
            'ultimo_balanco.max' => 'Cada documento pode ter no máximo 100 MB.',
            'dre.required' => 'Anexe a DRE.',
            'dre.max' => 'Cada documento pode ter no máximo 100 MB.',
            'politicas.required' => 'Anexe as políticas da empresa.',
            'politicas.max' => 'Cada documento pode ter no máximo 100 MB.',
            'cartao_cnpj.required' => 'Anexe o cartão CNPJ.',
            'cartao_cnpj.max' => 'Cada documento pode ter no máximo 100 MB.',
            'contrato_social.required' => 'Anexe o contrato social.',
            'contrato_social.max' => 'Cada documento pode ter no máximo 100 MB.',
            'estatuto.required' => 'Anexe o estatuto social.',
            'estatuto.max' => 'Cada documento pode ter no máximo 100 MB.',
            'procuracao.max' => 'Cada documento pode ter no máximo 100 MB.',
            'ata.max' => 'Cada documento pode ter no máximo 100 MB.',
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

            $totalDocumentBytes = collect(self::DOCUMENT_FIELDS)
                ->sum(fn (string $field): int => (int) ($this->file($field)?->getSize() ?? 0));

            if ($totalDocumentBytes > $this->submissionTotalMaxBytes()) {
                $validator->errors()->add(
                    'documents_total_size',
                    'O tamanho total de todos os arquivos não pode ultrapassar 100 MB.',
                );
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

    private function submissionFileMaxKb(): int
    {
        return (int) config('uploads.submission.max_kb', 102400);
    }

    private function submissionTotalMaxBytes(): int
    {
        return (int) config('uploads.submission.total_max_bytes', 100 * 1024 * 1024);
    }
}
