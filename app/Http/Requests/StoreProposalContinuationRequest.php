<?php

namespace App\Http\Requests;

use App\DTOs\Proposals\StoreProposalContinuationDataDTO;
use App\Models\ProposalContinuationAccess;
use App\Support\Proposals\ContinuationValidationRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreProposalContinuationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $access = $this->route('access');

        return $access instanceof ProposalContinuationAccess
            && $access->isActive()
            && $this->session()->has($access->magicLinkSessionKey())
            && $this->session()->has($access->verifiedSessionKey());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $access = $this->route('access');
        $proposalId = $access instanceof ProposalContinuationAccess ? $access->proposal_id : 0;

        return app(ContinuationValidationRules::class)->http($proposalId);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return app(ContinuationValidationRules::class)->messages();
    }

    public function toDTO(): StoreProposalContinuationDataDTO
    {
        return StoreProposalContinuationDataDTO::fromFlatPayload($this->validated());
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $access = $this->route('access');

            if (
                $access instanceof ProposalContinuationAccess
                && $access->proposal()->whereHas('projects')->exists()
                && ! is_array($this->input('project_id'))
            ) {
                $validator->errors()->add(
                    'project_id',
                    'Identifique os empreendimentos existentes antes de atualizar a proposta.',
                );
            }

            $this->validateParallelArrayLengths(
                $validator,
                'nome_empreendimento',
                [
                    'project_id',
                    'unidades_permutadas',
                    'unidades_quitadas',
                    'unidades_nao_quitadas',
                    'unidades_estoque',
                    'custo_incidido',
                    'custo_a_incorrer',
                    'valor_quitadas',
                    'valor_nao_quitadas',
                    'valor_estoque',
                    'valor_ja_recebido',
                    'valor_ate_chaves',
                    'valor_chaves_pos',
                ],
            );
            $this->validateParallelArrayLengths(
                $validator,
                'tipo_total',
                ['tipo_dormitorios', 'tipo_vagas', 'tipo_area', 'tipo_preco_medio'],
            );
        }];
    }

    /** @param array<int, string> $parallelFields */
    private function validateParallelArrayLengths(Validator $validator, string $referenceField, array $parallelFields): void
    {
        $reference = $this->input($referenceField);

        if (! is_array($reference)) {
            return;
        }

        foreach ($parallelFields as $field) {
            $values = $this->input($field);

            if ($values !== null && (! is_array($values) || count($values) !== count($reference))) {
                $validator->errors()->add(
                    $field,
                    'A quantidade de itens informada não corresponde aos registros principais.',
                );
            }
        }
    }
}
