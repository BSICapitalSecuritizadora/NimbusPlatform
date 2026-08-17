<?php

namespace App\Services\Guarantees;

use App\DTOs\Guarantees\EmissionGuaranteePositionData;
use App\Enums\GuaranteeCoverageStatus;
use App\Enums\GuaranteeDetectionStatus;
use App\Enums\GuaranteeLegalStatus;
use App\Enums\GuaranteeValueSource;
use App\Models\Emission;
use App\Models\ExtractedGuarantee;
use App\Models\Guarantee;
use App\Models\GuaranteeValuation;
use Carbon\Carbon;
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

        $this->addCoverageAlerts($alerts, $position);
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
