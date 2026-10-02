<?php

namespace App\Services\Guarantees;

use App\DTOs\Guarantees\EmissionGuaranteePositionData;
use App\DTOs\Guarantees\GuaranteeSalesBoardCoverage;
use App\Enums\GuaranteeCoverageStatus;
use App\Enums\GuaranteeDetectionStatus;
use App\Enums\GuaranteeLegalStatus;
use App\Enums\GuaranteeValueSource;
use App\Models\Emission;
use App\Models\ExtractedGuarantee;
use App\Models\Guarantee;
use App\Models\GuaranteeSnapshot;
use App\Models\GuaranteeValuation;
use App\Support\BusinessTime;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Pendências e riscos da aba de garantias (§29 do escopo).
 *
 * Os alertas são derivados da posição já apurada — não há tabela de alertas nem
 * agendador próprio. O módulo de obrigações continua sendo o lugar de tarefas
 * com prazo e responsável; aqui ficam só os avisos que a própria tela precisa
 * mostrar, e duplicar aquela infraestrutura seria criar um segundo sistema de
 * cobrança para o mesmo usuário.
 */
class GuaranteeAlertBuilder
{
    public const SEVERITY_DANGER = 'danger';

    public const SEVERITY_WARNING = 'warning';

    public const SEVERITY_INFO = 'info';

    /**
     * @return Collection<int, array{severity: string, title: string, description: string, guarantee_id: int|null}>
     */
    public function build(Emission $emission, EmissionGuaranteePositionData $position): Collection
    {
        $alerts = collect();

        $this->addCompetenceStateAlerts($alerts, $emission);
        $this->addCoverageAlerts($alerts, $position);
        $this->addSalesBoardCoverageAlert($alerts, $position);
        $this->addPositionAlerts($alerts, $position);
        $this->addGuaranteeAlerts($alerts, $emission, $position);
        $this->addDetectionAlerts($alerts, $emission);

        return $alerts->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $alerts
     */
    private function addCoverageAlerts(Collection $alerts, EmissionGuaranteePositionData $position): void
    {
        if ($position->coverageStatus === GuaranteeCoverageStatus::NonCompliant) {
            $alerts->push([
                'severity' => self::SEVERITY_DANGER,
                'title' => 'Cobertura abaixo do mínimo contratual',
                'description' => sprintf(
                    'A cobertura apurada em %s está abaixo do mínimo exigido. Déficit de %s.',
                    $position->referenceMonthLabel(),
                    $this->money($position->surplusDeficit),
                ),
                'guarantee_id' => null,
            ]);
        }

        if ($position->coverageStatus === GuaranteeCoverageStatus::NearLimit) {
            $alerts->push([
                'severity' => self::SEVERITY_WARNING,
                'title' => 'Cobertura próxima do limite',
                'description' => sprintf(
                    'A cobertura de %s está a menos de %d%% de margem sobre o mínimo contratual.',
                    $position->referenceMonthLabel(),
                    (int) round(GuaranteeCoverageStatus::NEAR_LIMIT_MARGIN * 100),
                ),
                'guarantee_id' => null,
            ]);
        }
    }

    /**
     * Avisos sobre as competências gravadas -- não sobre a apuração em tela:
     * as desatualizadas e as reabertas que ainda não foram fechadas de novo.
     *
     * Uma consulta só, para os dois avisos.
     *
     * @param  Collection<int, array<string, mixed>>  $alerts
     */
    private function addCompetenceStateAlerts(Collection $alerts, Emission $emission): void
    {
        if (! Emission::hasGuaranteeSnapshotsTable()) {
            return;
        }

        $snapshots = $emission->guaranteeSnapshots()
            ->where(fn (Builder $query): Builder => $query
                ->whereNotNull('sales_board_outdated_at')
                ->orWhereNotNull('outstanding_balance_outdated_at')
                ->orWhere(fn (Builder $reopened): Builder => $reopened->whereNotNull('reopened_at')->whereNull('closed_at')))
            ->with('reopenedBy')
            ->orderBy('reference_month')
            ->get();

        $this->addOutdatedCompetenceAlerts($alerts, $snapshots);
        $this->addReopenedCompetenceAlerts($alerts, $snapshots);
    }

    /**
     * Competências gravadas antes de uma mudança que passou a responder por
     * elas: um Quadro de Vendas registrado depois da apuração, ou o saldo
     * devedor recalculado. O número do snapshot — fechado ou não — já não é o
     * que o motor apuraria, e é isso que o relatório e o histórico mostram.
     *
     * @param  Collection<int, array<string, mixed>>  $alerts
     * @param  Collection<int, GuaranteeSnapshot>  $snapshots
     */
    private function addOutdatedCompetenceAlerts(Collection $alerts, Collection $snapshots): void
    {
        foreach ($snapshots as $snapshot) {
            /** @var GuaranteeSnapshot $snapshot */
            if ($snapshot->isSalesBoardOutdated()) {
                $alerts->push([
                    'severity' => self::SEVERITY_WARNING,
                    'title' => 'Competência desatualizada pelo Quadro de Vendas',
                    'description' => sprintf(
                        'A competência %s foi apurada antes de um Quadro de Vendas registrado em %s. %s',
                        $snapshot->formatted_reference_month,
                        BusinessTime::at($snapshot->sales_board_outdated_at)->format('d/m/Y H:i'),
                        $snapshot->isClosed()
                            ? 'Reabra e atualize a competência para refletir a posição publicada.'
                            : 'Atualize a competência para refletir a posição publicada.',
                    ),
                    'guarantee_id' => null,
                ]);
            }

            if ($snapshot->isOutstandingBalanceOutdated()) {
                $alerts->push([
                    'severity' => self::SEVERITY_WARNING,
                    'title' => 'Competência desatualizada pelo saldo devedor',
                    'description' => sprintf(
                        'A competência %s foi apurada antes de uma alteração no saldo devedor (%s) registrada em %s. %s',
                        $snapshot->formatted_reference_month,
                        filled($snapshot->outstanding_balance_outdated_reason)
                            ? $snapshot->outstanding_balance_outdated_reason
                            : 'fonte de PU alterada',
                        BusinessTime::at($snapshot->outstanding_balance_outdated_at)->format('d/m/Y H:i'),
                        $snapshot->isClosed()
                            ? 'Reabra e atualize a competência para refletir o saldo devedor atual.'
                            : 'Atualize a competência para refletir o saldo devedor atual.',
                    ),
                    'guarantee_id' => null,
                ]);
            }
        }
    }

    /**
     * Competências reabertas que ainda não foram fechadas de novo.
     *
     * Reabrir desfaz um número que já saiu em relatório. Enquanto o fechamento
     * não volta, o relatório da competência usa a apuração ao vivo, rotulada
     * como preliminar -- e é isso que este aviso cobra.
     *
     * @param  Collection<int, array<string, mixed>>  $alerts
     * @param  Collection<int, GuaranteeSnapshot>  $snapshots
     */
    private function addReopenedCompetenceAlerts(Collection $alerts, Collection $snapshots): void
    {
        foreach ($snapshots as $snapshot) {
            /** @var GuaranteeSnapshot $snapshot */
            if (! $snapshot->wasReopenedAndNotClosed()) {
                continue;
            }

            $alerts->push([
                'severity' => self::SEVERITY_WARNING,
                'title' => 'Competência reaberta e não fechada',
                'description' => sprintf(
                    'A competência %s foi reaberta em %s%s%s e ainda não foi fechada de novo. Atualize e feche a competência para que o número volte a ser o consolidado.',
                    $snapshot->formatted_reference_month,
                    BusinessTime::at($snapshot->reopened_at)->format('d/m/Y H:i'),
                    $snapshot->reopenedBy === null ? '' : ' por '.$snapshot->reopenedBy->name,
                    filled($snapshot->reopen_reason) ? ' (motivo: '.$snapshot->reopen_reason.')' : '',
                ),
                'guarantee_id' => null,
            ]);
        }
    }

    /**
     * Garantias de estoque apuradas sem o quadro da própria competência em
     * algum empreendimento. O valor é a melhor posição conhecida, não a do mês.
     *
     * Na competência corrente, a posição transportada é o esperado: o quadro do
     * mês só é publicado depois de o mês acabar. Avisar isso como pendência
     * deixaria o alerta aceso o tempo todo em toda emissão com estoque — e um
     * aviso que nunca apaga ensina a ignorar os reais. Ali ela vira nota
     * informativa, com o mês usado por empreendimento à vista. O empreendimento
     * que nunca teve quadro continua pendência em qualquer competência, e nas
     * competências passadas — as que se atualizam e fecham — a posição
     * transportada também.
     *
     * @param  Collection<int, array<string, mixed>>  $alerts
     */
    private function addSalesBoardCoverageAlert(Collection $alerts, EmissionGuaranteePositionData $position): void
    {
        $coverage = $position->salesBoardCoverage;

        if (! $coverage?->hasGaps()) {
            return;
        }

        $pendingGaps = $coverage->gaps();

        if ($position->referenceMonth >= GuaranteeSnapshot::currentBusinessMonth()) {
            $pendingGaps = $coverage->unpositionedGaps();
            $carriedForward = $coverage->carriedForwardGaps();

            if ($carriedForward !== []) {
                $alerts->push([
                    'severity' => self::SEVERITY_INFO,
                    'title' => 'Quadro de Vendas do mês ainda não publicado',
                    'description' => sprintf(
                        'O quadro de %s só é publicado depois do fim do mês; até lá, vale a última posição conhecida de cada empreendimento: %s.',
                        $position->referenceMonthLabel(),
                        implode('; ', array_map(
                            fn (array $entry): string => GuaranteeSalesBoardCoverage::describeMonthUsed($entry),
                            $carriedForward,
                        )),
                    ),
                    'guarantee_id' => null,
                ]);
            }
        }

        if ($pendingGaps === []) {
            return;
        }

        $alerts->push([
            'severity' => self::SEVERITY_WARNING,
            'title' => 'Posição parcial do Quadro de Vendas',
            'description' => sprintf(
                'Em %s: %s.',
                $position->referenceMonthLabel(),
                $this->describeSalesBoardGaps($pendingGaps),
            ),
            'guarantee_id' => null,
        ]);
    }

    /**
     * @param  list<array{construction_id: int, construction_name: string|null, status: string, reference_month_used: string|null}>  $gaps
     */
    private function describeSalesBoardGaps(array $gaps): string
    {
        return implode('; ', array_map(
            fn (array $entry): string => GuaranteeSalesBoardCoverage::describeEntry($entry),
            $gaps,
        ));
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $alerts
     */
    private function addPositionAlerts(Collection $alerts, EmissionGuaranteePositionData $position): void
    {
        foreach ($position->pendingPositions() as $pending) {
            $alerts->push([
                'severity' => self::SEVERITY_WARNING,
                'title' => 'Valor da competência pendente',
                'description' => sprintf(
                    '%s ainda não tem valor informado para %s.',
                    $pending->guarantee->display_name,
                    $position->referenceMonthLabel(),
                ),
                'guarantee_id' => $pending->guarantee->getKey(),
            ]);
        }

        foreach ($position->breachingPositions() as $breach) {
            // O déficit consolidado já foi noticiado acima; aqui interessa o
            // mínimo próprio de fundos e contas, que não entra no índice geral.
            if ($breach->guarantee->type?->category()->value !== 'fund_account') {
                continue;
            }

            $alerts->push([
                'severity' => self::SEVERITY_DANGER,
                'title' => 'Fundo abaixo do mínimo exigido',
                'description' => sprintf(
                    '%s está %s abaixo do mínimo contratual.',
                    $breach->guarantee->display_name,
                    $this->money(abs((float) $breach->surplusDeficit)),
                ),
                'guarantee_id' => $breach->guarantee->getKey(),
            ]);
        }
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $alerts
     */
    private function addGuaranteeAlerts(
        Collection $alerts,
        Emission $emission,
        EmissionGuaranteePositionData $position,
    ): void {
        $referenceEnd = Carbon::parse($position->referenceMonth)->endOfMonth();

        /** @var Collection<int, Guarantee> $guarantees */
        $guarantees = $emission->guarantees()->with('valuations')->get();

        foreach ($guarantees as $guarantee) {
            if ($guarantee->validity_end_date !== null && $guarantee->validity_end_date->lt($referenceEnd)) {
                $alerts->push([
                    'severity' => self::SEVERITY_WARNING,
                    'title' => 'Garantia vencida',
                    'description' => sprintf(
                        '%s venceu em %s.',
                        $guarantee->display_name,
                        $guarantee->validity_end_date->format('d/m/Y'),
                    ),
                    'guarantee_id' => $guarantee->getKey(),
                ]);
            }

            if ($guarantee->legal_status === GuaranteeLegalStatus::NotDocumented) {
                $alerts->push([
                    'severity' => self::SEVERITY_WARNING,
                    'title' => 'Garantia sem documento comprobatório',
                    'description' => sprintf(
                        '%s não possui origem documental registrada.',
                        $guarantee->display_name,
                    ),
                    'guarantee_id' => $guarantee->getKey(),
                ]);
            }

            if ($guarantee->legal_status === GuaranteeLegalStatus::Inconsistent) {
                $alerts->push([
                    'severity' => self::SEVERITY_DANGER,
                    'title' => 'Dados divergentes',
                    'description' => sprintf('%s está marcada como inconsistente.', $guarantee->display_name),
                    'guarantee_id' => $guarantee->getKey(),
                ]);
            }

            $this->addValuationAlert($alerts, $guarantee, $referenceEnd);
        }
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $alerts
     */
    private function addValuationAlert(Collection $alerts, Guarantee $guarantee, Carbon $referenceEnd): void
    {
        if ($guarantee->resolvedValueSource() !== GuaranteeValueSource::Valuation) {
            return;
        }

        $valuation = $guarantee->valuationAsOf($referenceEnd);

        if (! $valuation instanceof GuaranteeValuation) {
            return;
        }

        if (! $valuation->isExpiredOn($referenceEnd)) {
            return;
        }

        $alerts->push([
            'severity' => self::SEVERITY_WARNING,
            'title' => 'Avaliação vencida',
            'description' => sprintf(
                'A avaliação de %s perdeu validade em %s.',
                $guarantee->display_name,
                $valuation->valid_until->format('d/m/Y'),
            ),
            'guarantee_id' => $guarantee->getKey(),
        ]);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $alerts
     */
    private function addDetectionAlerts(Collection $alerts, Emission $emission): void
    {
        $pending = $emission->extractedGuarantees()
            ->where('status', GuaranteeDetectionStatus::Suggested->value)
            ->get();

        if ($pending->isEmpty()) {
            return;
        }

        $conflicts = $pending->where('has_conflict', true);

        if ($conflicts->isNotEmpty()) {
            $alerts->push([
                'severity' => self::SEVERITY_DANGER,
                'title' => 'Conflito documental — revisão necessária',
                'description' => sprintf(
                    '%d garantia(s) detectada(s) divergem das informações já confirmadas.',
                    $conflicts->count(),
                ),
                'guarantee_id' => null,
            ]);
        }

        $releases = $pending->filter(
            fn (ExtractedGuarantee $candidate): bool => $candidate->event_type?->value === 'release',
        );

        if ($releases->isNotEmpty()) {
            $alerts->push([
                'severity' => self::SEVERITY_WARNING,
                'title' => 'Documento indica liberação de garantia',
                'description' => sprintf(
                    '%d liberação(ões) identificada(s) em documento aguardam revisão.',
                    $releases->count(),
                ),
                'guarantee_id' => null,
            ]);
        }

        $substitutions = $pending->filter(
            fn (ExtractedGuarantee $candidate): bool => $candidate->event_type?->value === 'substitution',
        );

        if ($substitutions->isNotEmpty()) {
            $alerts->push([
                'severity' => self::SEVERITY_WARNING,
                'title' => 'Documento indica substituição ainda não revisada',
                'description' => sprintf(
                    '%d substituição(ões) identificada(s) em documento aguardam revisão.',
                    $substitutions->count(),
                ),
                'guarantee_id' => null,
            ]);
        }
    }

    private function money(?float $value): string
    {
        if ($value === null) {
            return 'valor não informado';
        }

        return 'R$ '.number_format(abs($value), 2, ',', '.');
    }
}
