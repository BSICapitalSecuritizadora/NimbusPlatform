<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\DTOs\SalesBoards\SalesBoardBuilderResponseView;
use App\DTOs\SalesBoards\SalesBoardBuilderWorkspaceBucket;
use App\DTOs\SalesBoards\SalesBoardComparableSnapshot;
use App\DTOs\SalesBoards\SalesBoardManagementNonconformityRow;
use App\DTOs\SalesBoards\SalesBoardManagementReviewWorkspace;
use App\DTOs\SalesBoards\SalesBoardPriorPosition;
use App\DTOs\SalesBoards\SalesBoardPublicationPayload;
use App\Enums\SalesBoardBuilderDivergenceType;
use App\Enums\SalesBoardIssueCode;
use App\Enums\SalesBoardNonconformityOrigin;
use App\Enums\SalesBoardRectificationStatus;
use App\Exceptions\SalesBoardManagementReviewException;
use App\Models\SalesBoardBuilderDivergence;
use App\Models\SalesBoardBuilderReviewSection;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleBaseline;
use App\Models\SalesBoardCycleMovement;
use App\Models\SalesBoardCycleRectification;
use App\Models\SalesBoardManagementNonconformity;
use App\Models\SalesBoardManagementReview;
use App\Support\BusinessTime;
use App\Support\Money\IntegerMoney;
use App\Support\SalesBoards\PublishedCompetenceBoundary;
use App\Support\SalesBoards\SalesBoardIssuePresenter;
use App\Support\SalesBoards\UnitDisplayOrder;
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
 * apenas responde se a posição ainda pode ser publicada. Na análise aprovada
 * nem ela: a posição já foi publicada, e o portão ao vivo daria "Publicação
 * bloqueada" ao lado de "publicada" -- a tela mostra quando e por quem a
 * análise foi aprovada.
 *
 * Também saem daqui os movimentos de competências anteriores, com a
 * conformidade (área interna), a "Ponte com a competência anterior" e, na
 * retificação, o motivo e o que a versão analisada muda contra a posição
 * publicada -- duas versões congeladas comparadas.
 */
class SalesBoardManagementReviewWorkspaceBuilder
{
    public function __construct(
        private readonly SalesBoardManagementApprovalService $approvalService,
        private readonly SalesBoardPublicationProjection $projection,
        private readonly SalesBoardPriorPositionResolver $priorPositionResolver,
        private readonly SalesBoardCompetenceBridgeBuilder $bridgeBuilder,
        private readonly SalesBoardBaselineDiffService $diffService,
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
            'builderReview.attachments',
            'nonconformities.builderDivergence.section',
            'nonconformities.movement',
            'nonconformities.decidedBy',
            'approvedBy',
        ]);

        $cycle = $review->cycle;
        $baseline = $review->baseline;
        $builderReview = $review->builderReview;

        $anchor = $cycle === null ? null : $this->priorPositionResolver->forCycle($cycle);
        $bridge = $baseline === null ? null : $this->bridgeBuilder->forBaselineWithAnchor($baseline, $anchor);
        $rectification = $this->openRectification($cycle);

        $gate = $review->isApproved()
            ? ['ready' => false, 'checks' => [], 'impact' => null]
            : $this->approvalService->gate($review, $bridge);

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
            nonconformities: $this->nonconformityRows($review, $anchor, $rectification !== null),
            gate: $gate,
            publicationPreview: $this->publicationPreview($review),
            returnReason: $review->return_reason,
            sourceChangeReason: $review->source_change_reason,
            approvedAt: $review->approved_at,
            approvedByName: $review->approvedBy?->name,
            builderResponse: ($builderReview?->submitted_at === null) ? null : SalesBoardBuilderResponseView::fromReview($builderReview),
            approvedBySubmitter: ($review->approved_by_user_id !== null)
                && ((int) $review->approved_by_user_id === (int) $builderReview?->submitted_by_user_id),
            warnings: SalesBoardIssuePresenter::groupFrozen($baseline?->frozenWarnings(), except: [
                SalesBoardIssueCode::SaleNonConform,
                SalesBoardIssueCode::LateSaleNonConform,
                SalesBoardIssueCode::LateSaleUndetermined,
            ]),
            bridge: $bridge,
            lateMovements: $baseline === null ? [] : $this->lateMovements($baseline, $anchor),
            rectification: $rectification === null ? null : [
                'reason' => (string) $rectification->reason,
                'requested_by' => $rectification->requestedBy?->name,
                'requested_at' => $rectification->requested_at,
                'published_version' => $rectification->rectifiedPublication?->baseline?->versionLabel(),
                'published_at' => $rectification->rectifiedPublication?->published_at,
            ],
            publishedDiff: ($rectification === null || $baseline === null) ? null : $this->publishedDiff($rectification, $baseline),
            approvedSummary: $review->isApproved()
                ? sprintf(
                    'Aprovada e publicada em %s por %s.',
                    $review->approved_at === null ? '—' : BusinessTime::at($review->approved_at)->format('d/m/Y \à\s H:i'),
                    $review->approvedBy?->name ?? '—',
                )
                : null,
        );
    }

    private function openRectification(?SalesBoardCycle $cycle): ?SalesBoardCycleRectification
    {
        if ($cycle === null) {
            return null;
        }

        return SalesBoardCycleRectification::query()
            ->where('sales_board_cycle_id', $cycle->getKey())
            ->where('status', SalesBoardRectificationStatus::Open->value)
            ->with(['requestedBy', 'rectifiedPublication.baseline'])
            ->first();
    }

    /**
     * O que a versão analisada muda contra a posição publicada: duas versões
     * congeladas, comparadas sem tocar a fonte viva.
     */
    private function publishedDiff(SalesBoardCycleRectification $rectification, SalesBoardCycleBaseline $baseline): ?string
    {
        $published = $rectification->rectifiedPublication?->baseline;

        return $published === null
            ? null
            : $this->diffService->compare(
                SalesBoardComparableSnapshot::fromBaseline($published),
                SalesBoardComparableSnapshot::fromBaseline($baseline),
            )->summary();
    }

    /**
     * Os movimentos de competências anteriores da versão analisada, com a
     * conformidade e o selo -- os extemporâneos e as revisões de venda
     * primeiro, na ordem da data, e os de competência sem posição depois.
     *
     * @return list<array{type: string, unit: string, contract: string|null, timing: string, timing_color: string, date: string|null, value: string, conformity: string|null}>
     */
    private function lateMovements(SalesBoardCycleBaseline $baseline, ?SalesBoardPriorPosition $anchor): array
    {
        $baseline->loadMissing('movements');

        return $baseline->movements
            ->filter(fn (SalesBoardCycleMovement $movement): bool => $movement->timing !== null)
            ->sort(fn (SalesBoardCycleMovement $left, SalesBoardCycleMovement $right): int => ((int) ! $left->timing->isLate() <=> (int) ! $right->timing->isLate())
                ?: ((int) ($left->event_date === null) <=> (int) ($right->event_date === null))
                ?: ((string) $left->event_date?->toDateString() <=> (string) $right->event_date?->toDateString())
                ?: UnitDisplayOrder::compare($left->block, $left->unit, $right->block, $right->unit)
                ?: ((int) $left->getKey() <=> (int) $right->getKey()))
            ->map(fn (SalesBoardCycleMovement $movement): array => [
                'type' => $movement->movement_type->label(),
                'unit' => $movement->displayName(),
                'contract' => $movement->contract_code,
                'timing' => (string) $this->timingLabel($movement, $anchor),
                'timing_color' => $movement->timing->color(),
                'date' => $movement->event_date?->format('d/m/Y'),
                'value' => SalesBoardManagementNonconformityRow::money(IntegerMoney::cents($movement->sale_value)),
                'conformity' => $movement->conformity_status?->label(),
            ])
            ->values()
            ->all();
    }

    private function timingLabel(SalesBoardCycleMovement $movement, ?SalesBoardPriorPosition $anchor): ?string
    {
        $prior = $anchor?->line((int) $movement->construction_unit_id);

        return $movement->timingLabel(
            $anchor?->referenceMonth,
            $anchor?->isPublished ?? true,
            $prior?->saleValueCents,
            $prior?->saleDate === null ? null : CarbonImmutable::parse($prior->saleDate),
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
    private function nonconformityRows(SalesBoardManagementReview $review, ?SalesBoardPriorPosition $anchor = null, bool $underRectification = false): array
    {
        return $review->nonconformities
            ->map(fn (SalesBoardManagementNonconformity $item): SalesBoardManagementNonconformityRow => match ($item->origin) {
                SalesBoardNonconformityOrigin::SystemSaleNonConform,
                SalesBoardNonconformityOrigin::SystemSaleUndetermined,
                SalesBoardNonconformityOrigin::SystemLateSaleWithoutPolicy => $this->systemRow($item, $anchor),
                SalesBoardNonconformityOrigin::BuilderDeclared => $this->builderRow($item, $review->cycle, $underRectification),
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
    private function systemRow(SalesBoardManagementNonconformity $item, ?SalesBoardPriorPosition $anchor = null): SalesBoardManagementNonconformityRow
    {
        /** @var SalesBoardCycleMovement $movement */
        $movement = $item->movement;

        $money = fn (mixed $value): string => SalesBoardManagementNonconformityRow::money(IntegerMoney::cents($value));

        $undetermined = in_array(
            $item->origin,
            [SalesBoardNonconformityOrigin::SystemSaleUndetermined, SalesBoardNonconformityOrigin::SystemLateSaleWithoutPolicy],
            true,
        );

        return new SalesBoardManagementNonconformityRow(
            id: (int) $item->getKey(),
            origin: $item->origin,
            typeLabel: match ($item->origin) {
                SalesBoardNonconformityOrigin::SystemLateSaleWithoutPolicy => 'Venda de competência publicada sem política aplicável',
                SalesBoardNonconformityOrigin::SystemSaleUndetermined => 'Conformidade não determinada pelo Nimbus',
                default => 'Venda abaixo do mínimo autorizado',
            },
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
            timingLabel: $this->timingLabel($movement, $anchor),
        );
    }

    /**
     * Uma declaração da construtora, com as duas versões do fato lado a lado.
     *
     * O que o Nimbus apurou vem da linha ou do movimento ancorado; o que a
     * construtora afirma vem dos campos declarados. Não existe botão para editar
     * o lado do Nimbus: se ele estiver errado, o caminho é corrigir a fonte e
     * recalcular.
     *
     * A unidade que a construtora diz não existir tem esse caminho conhecido --
     * a baixa da unidade e o recálculo --, e a linha o descreve, com o link para
     * a unidade. Tudo do fato congelado, do mês do ciclo e da fronteira
     * publicada da obra: a tela da Gestão decide e não edita, e não consulta o
     * cadastro vivo.
     */
    private function builderRow(SalesBoardManagementNonconformity $item, ?SalesBoardCycle $cycle, bool $underRectification = false): SalesBoardManagementNonconformityRow
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

        $unitNonexistent = $divergence->type === SalesBoardBuilderDivergenceType::UnitNonexistent;

        return new SalesBoardManagementNonconformityRow(
            id: (int) $item->getKey(),
            origin: $item->origin,
            typeLabel: $divergence->type->label(),
            unitLabel: $unitLabel === '—' ? null : $unitLabel,
            contractCode: $contractCode,
            systemStatement: $this->systemStatementFor($divergence),
            builderStatement: $unitNonexistent ? 'A unidade não existe' : $this->builderStatementFor($divergence),
            builderReason: $divergence->reason,
            facts: $facts,
            decision: $item->decision,
            decisionReason: $item->decision_reason,
            decidedByName: $item->decidedBy?->name,
            decidedAt: $item->decided_at,
            correctionGuidance: $unitNonexistent ? $this->retirementGuidance($unitLabel, $cycle, $underRectification) : null,
            unitToRetireId: $unitNonexistent && ($line?->construction_unit_id !== null) ? (int) $line->construction_unit_id : null,
        );
    }

    /**
     * A saída da unidade que não existe, dita à Gestão: a baixa e o recálculo,
     * que devolve a versão nova à construtora.
     *
     * A data é o primeiro dia da competência -- assim a unidade sai dela e das
     * seguintes --, ou o primeiro dia ainda não publicado da obra quando ele é
     * anterior: com a competência anterior também em aberto, a baixa no mês do
     * ciclo deixaria a unidade inexistente nela, e o cadastro da baixa
     * recomenda a data mais antiga ainda não publicada.
     *
     * Na retificação a competência já foi publicada, e a baixa não vale dentro
     * dela: o serviço recusa a data e o seletor nem a oferece. A orientação
     * não manda pelo caminho impossível -- "Correção necessária" deixaria a
     * republicação recusada sem saída pela baixa. Ela diz o que resta: a baixa
     * a partir da competência seguinte e, aqui, "Não procede" ou desistir da
     * retificação.
     */
    private function retirementGuidance(string $unitLabel, ?SalesBoardCycle $cycle, bool $underRectification): string
    {
        $cycleMonth = $cycle?->reference_month === null
            ? null
            : CarbonImmutable::parse($cycle->reference_month->toDateString())->startOfMonth();
        $firstOpenDay = $cycle === null ? null : PublishedCompetenceBoundary::firstOpenDay((int) $cycle->construction_id);

        if ($underRectification) {
            return sprintf(
                'Esta competência já foi publicada e está em retificação: a baixa da unidade %s não vale dentro dela, só a partir de %s (aba "Baixas" da unidade; exige a permissão de aprovação do Quadro e acesso ao cadastro de unidades), o que a tira das competências seguintes. '
                    .'Neste mês a correção não passa pela baixa, e "Correção necessária" deixaria a republicação recusada: decida "Não procede", registrando que a correção vale a partir da competência seguinte, ou desista da retificação.',
                $unitLabel,
                $firstOpenDay?->format('d/m/Y') ?? '—',
            );
        }

        $retiredFrom = (($firstOpenDay !== null) && ($cycleMonth !== null) && $firstOpenDay->lessThan($cycleMonth))
            ? $firstOpenDay
            : $cycleMonth;

        return sprintf(
            'Correção: registre a baixa da unidade %s com efeito a partir de %s (aba "Baixas" da unidade; exige a permissão de aprovação do Quadro e acesso ao cadastro de unidades) e recalcule a posição. '
                .'Se a unidade tiver contrato ou permuta, corrija-os antes: a baixa exige a unidade livre. A nova versão volta à construtora.',
            $unitLabel,
            $retiredFrom?->format('d/m/Y') ?? '—',
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
