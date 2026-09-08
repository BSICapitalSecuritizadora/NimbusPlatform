<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\DTOs\SalesBoards\SalesBoardBuilderWorkspaceBucket;
use App\DTOs\SalesBoards\SalesBoardManagementNonconformityRow;
use App\DTOs\SalesBoards\SalesBoardManagementReviewWorkspace;
use App\DTOs\SalesBoards\SalesBoardPublicationPayload;
use App\Enums\SalesBoardNonconformityOrigin;
use App\Exceptions\SalesBoardManagementReviewException;
use App\Models\SalesBoardBuilderDivergence;
use App\Models\SalesBoardBuilderReviewSection;
use App\Models\SalesBoardCycleMovement;
use App\Models\SalesBoardManagementNonconformity;
use App\Models\SalesBoardManagementReview;
use App\Support\Money\IntegerMoney;
use Carbon\CarbonImmutable;

/**
 * Monta o que a Gestão vê, a partir do que foi congelado e do que foi declarado.
 *
 * Lê o baseline da **análise**, não o vigente do ciclo. Se uma nova versão
 * nascer enquanto a Gestão analisa, a tela precisa continuar mostrando os fatos
 * sobre os quais as decisões foram tomadas -- trocar o conteúdo por baixo
 * transformaria a análise em outra coisa no meio do caminho. É por isso que a
 * aprovação é recusada nesse cenário, com mensagem, em vez de a tela mudar
 * sozinha.
 *
 * Nenhuma consulta toca contrato, parcela, tabela de preço ou permuta viva. A
 * política comercial exibida é a que foi congelada no movimento, não a vigente:
 * reler a de hoje para explicar um apontamento de três meses atrás daria uma
 * explicação que nunca existiu.
 *
 * A única leitura viva é a situação da fonte, e ela não monta fato nenhum --
 * apenas responde se a posição ainda pode ser publicada.
 */
class SalesBoardManagementReviewWorkspaceBuilder
{
    public function __construct(
        private readonly SalesBoardManagementApprovalService $approvalService,
        private readonly SalesBoardPublicationProjection $projection,
    ) {}

    public function build(SalesBoardManagementReview $review): SalesBoardManagementReviewWorkspace
    {
        $review->loadMissing([
            'cycle.construction',
            'cycle.emission',
            'cycle.currentBaseline',
            'baseline',
            'builderReview.sections',
            'builderReview.divergences',
            'nonconformities.builderDivergence.section',
            'nonconformities.movement',
            'nonconformities.decidedBy',
            'approvedBy',
        ]);

        $cycle = $review->cycle;
        $baseline = $review->baseline;
        $builderReview = $review->builderReview;

        $gate = $this->approvalService->gate($review);

        return new SalesBoardManagementReviewWorkspace(
            reviewId: (int) $review->getKey(),
            emissionName: (string) ($cycle?->emission?->name ?? '—'),
            constructionName: (string) ($cycle?->construction?->development_name ?? '—'),
            referenceMonth: $cycle?->reference_month?->format('m/Y') ?? '—',
            positionDate: CarbonImmutable::parse($cycle->position_date->toDateString()),
            baselineLabel: $baseline?->versionLabel() ?? '—',
            builderAttempt: (int) ($builderReview?->attempt ?? 0),
            managementAttempt: (int) $review->attempt,
            status: $review->status,
            isApplicable: $review->appliesTo($cycle?->currentBaseline),
            unitsTotal: (int) $baseline->units_total,
            buckets: [
                new SalesBoardBuilderWorkspaceBucket('Estoque', (int) $baseline->stock_units, IntegerMoney::cents($baseline->stock_value)),
                new SalesBoardBuilderWorkspaceBucket('Financiado', (int) $baseline->financed_units, IntegerMoney::cents($baseline->financed_value)),
                new SalesBoardBuilderWorkspaceBucket('Quitado', (int) $baseline->settled_units, IntegerMoney::cents($baseline->settled_value)),
                new SalesBoardBuilderWorkspaceBucket('Permutado', (int) $baseline->exchanged_units, IntegerMoney::cents($baseline->exchanged_value)),
            ],
            builderReviewerName: $builderReview?->reviewer_name,
            builderSubmittedAt: $builderReview?->submitted_at,
            builderFullyConfirmed: ($builderReview?->divergences->isEmpty() ?? false)
                && ($builderReview?->isFullyConfirmed() ?? false),
            builderDivergenceCount: (int) ($builderReview?->divergences->count() ?? 0),
            builderOverallComment: $builderReview?->overall_comment,
            builderSections: $this->builderSections($review),
            nonconformities: $this->nonconformityRows($review),
            gate: $gate,
            publicationPreview: $this->publicationPreview($review),
            returnReason: $review->return_reason,
            sourceChangeReason: $review->source_change_reason,
            approvedAt: $review->approved_at,
            approvedByName: $review->approvedBy?->name,
        );
    }

    /**
     * As sete seções como a construtora as respondeu.
     *
     * @return list<array{label: string, status: string, color: string, comment: string|null, divergences: int}>
     */
    private function builderSections(SalesBoardManagementReview $review): array
    {
        $builderReview = $review->builderReview;

        if ($builderReview === null) {
            return [];
        }

        $counts = $builderReview->divergences
            ->groupBy('sales_board_builder_review_section_id')
            ->map(fn ($group): int => $group->count());

        return $builderReview->sections
            ->map(fn (SalesBoardBuilderReviewSection $section): array => [
                'label' => $section->section->label(),
                'status' => $section->status->label(),
                'color' => $section->status->color(),
                'comment' => $section->comment,
                'divergences' => (int) ($counts[$section->getKey()] ?? 0),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<SalesBoardManagementNonconformityRow>
     */
    private function nonconformityRows(SalesBoardManagementReview $review): array
    {
        return $review->nonconformities
            ->map(fn (SalesBoardManagementNonconformity $item): SalesBoardManagementNonconformityRow => match ($item->origin) {
                SalesBoardNonconformityOrigin::SystemSaleNonConform,
                SalesBoardNonconformityOrigin::SystemSaleUndetermined => $this->systemRow($item),
                SalesBoardNonconformityOrigin::BuilderDeclared => $this->builderRow($item),
            })
            ->values()
            ->all();
    }

    /**
     * Um apontamento do Nimbus sobre uma venda, aberto pelos números congelados.
     *
     * Tudo vem do movimento: a tabela usada, a política aplicada, o desconto
     * autorizado, o mínimo, o praticado e a diferença. Esta é área interna, e é
     * aqui -- e só aqui -- que a política comercial pode aparecer.
     *
     * Os dois apontamentos usam a mesma abertura porque os fatos são os mesmos;
     * o que muda é o que o Nimbus conseguiu concluir a partir deles. Numa venda
     * indeterminada as células que faltam aparecem vazias, que é exatamente a
     * informação: é a ausência delas que impede o veredito. Por isso o motivo
     * congelado vira a afirmação principal em vez de um rodapé.
     */
    private function systemRow(SalesBoardManagementNonconformity $item): SalesBoardManagementNonconformityRow
    {
        /** @var SalesBoardCycleMovement $movement */
        $movement = $item->movement;

        $money = fn (mixed $value): string => SalesBoardManagementNonconformityRow::money(IntegerMoney::cents($value));

        $undetermined = $item->origin === SalesBoardNonconformityOrigin::SystemSaleUndetermined;

        return new SalesBoardManagementNonconformityRow(
            id: (int) $item->getKey(),
            origin: $item->origin,
            typeLabel: $undetermined
                ? 'Conformidade não determinada pelo Nimbus'
                : 'Venda abaixo do mínimo autorizado',
            unitLabel: $movement->displayName(),
            contractCode: $movement->contract_code,
            systemStatement: $undetermined
                /**
                 * O motivo congelado é a explicação, e ele já vem em português
                 * funcional do avaliador -- "o empreendimento não tinha política
                 * de desconto vigente na data da venda". Reescrevê-lo aqui
                 * criaria uma segunda versão da mesma frase para divergirem.
                 */
                ? (string) ($movement->conformity_reason ?? 'O Nimbus não conseguiu determinar a conformidade desta venda.')
                : sprintf(
                    'Venda por %s, mínimo autorizado %s.',
                    $money($movement->sale_value),
                    $money($movement->minimum_authorized_value),
                ),
            builderStatement: null,
            builderReason: null,
            facts: [
                ['label' => 'Data da venda', 'value' => $movement->sale_date?->format('d/m/Y') ?? '—'],
                ['label' => 'Valor da venda', 'value' => $money($movement->sale_value)],
                ['label' => 'Valor de referência', 'value' => $money($movement->unit_reference_value)],
                ['label' => 'Desconto autorizado', 'value' => $movement->authorizedDiscountLabel() ?? '—'],
                ['label' => 'Preço mínimo', 'value' => $money($movement->minimum_authorized_value)],
                ['label' => 'Desconto praticado', 'value' => $movement->effectiveDiscountLabel() ?? '—'],
                ['label' => 'Diferença', 'value' => $money($movement->difference_value)],
                ['label' => 'Conformidade', 'value' => SalesBoardManagementNonconformityRow::conformityLabel($movement->conformity_status)],
            ],
            decision: $item->decision,
            decisionReason: $item->decision_reason,
            decidedByName: $item->decidedBy?->name,
            decidedAt: $item->decided_at,
        );
    }

    /**
     * Uma declaração da construtora, com as duas versões do fato lado a lado.
     *
     * O que o Nimbus apurou vem da linha ou do movimento ancorado; o que a
     * construtora afirma vem dos campos declarados. Não existe botão para editar
     * o lado do Nimbus: se ele estiver errado, o caminho é corrigir a fonte e
     * recalcular.
     */
    private function builderRow(SalesBoardManagementNonconformity $item): SalesBoardManagementNonconformityRow
    {
        /** @var SalesBoardBuilderDivergence $divergence */
        $divergence = $item->builderDivergence;

        $line = $divergence->line;
        $movement = $divergence->movement;

        $money = fn (mixed $value): string => SalesBoardManagementNonconformityRow::money(IntegerMoney::cents($value));

        /**
         * Os rótulos vêm da própria divergência: ela já sabe compor a melhor
         * forma disponível, inclusive quando a unidade citada não existe no
         * Nimbus. Recompor aqui produziria uma segunda regra para o mesmo texto.
         */
        $unitLabel = $divergence->unitLabel();
        $contractCode = $divergence->contractLabel();

        $facts = array_values(array_filter([
            $line === null ? null : ['label' => 'Situação apurada', 'value' => $line->classification->label()],
            $line?->contract_sale_value === null ? null : ['label' => 'Valor apurado', 'value' => $money($line->contract_sale_value)],
            $line?->contract_sale_date === null ? null : ['label' => 'Data apurada', 'value' => $line->contract_sale_date->format('d/m/Y')],
            $movement?->sale_value === null ? null : ['label' => 'Valor apurado', 'value' => $money($movement->sale_value)],
            $movement?->event_date === null ? null : ['label' => 'Data apurada', 'value' => $movement->event_date->format('d/m/Y')],
        ]));

        return new SalesBoardManagementNonconformityRow(
            id: (int) $item->getKey(),
            origin: $item->origin,
            typeLabel: $divergence->type->label(),
            unitLabel: $unitLabel === '—' ? null : $unitLabel,
            contractCode: $contractCode,
            systemStatement: $this->systemStatementFor($divergence),
            builderStatement: $this->builderStatementFor($divergence),
            builderReason: $divergence->reason,
            facts: $facts,
            decision: $item->decision,
            decisionReason: $item->decision_reason,
            decidedByName: $item->decidedBy?->name,
            decidedAt: $item->decided_at,
        );
    }

    private function systemStatementFor(SalesBoardBuilderDivergence $divergence): ?string
    {
        $line = $divergence->line;
        $movement = $divergence->movement;

        if ($line !== null) {
            return $line->classification->label();
        }

        if ($movement !== null) {
            return sprintf(
                '%s em %s',
                $movement->movement_type->label(),
                $movement->event_date?->format('d/m/Y') ?? '—',
            );
        }

        return 'Não consta no quadro apurado';
    }

    private function builderStatementFor(SalesBoardBuilderDivergence $divergence): ?string
    {
        $parts = array_filter([
            $divergence->declared_classification?->label(),
            $divergence->declared_value === null
                ? null
                : SalesBoardManagementNonconformityRow::money(IntegerMoney::cents($divergence->declared_value)),
            $divergence->declared_date?->format('d/m/Y'),
            $divergence->declared_contract_code,
        ]);

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /**
     * A prévia do que será escrito no Quadro de Vendas legado.
     *
     * Só existe quando a posição é publicável. Um baseline incompleto não tem
     * projeção possível, e mostrar zeros no lugar dos valores ausentes daria à
     * confirmação a aparência de um quadro fechado.
     */
    private function publicationPreview(SalesBoardManagementReview $review): ?SalesBoardPublicationPayload
    {
        $cycle = $review->cycle;
        $baseline = $review->baseline;

        if ($cycle === null || $baseline === null) {
            return null;
        }

        try {
            return $this->projection->project($cycle, $baseline);
        } catch (SalesBoardManagementReviewException) {
            return null;
        }
    }
}
