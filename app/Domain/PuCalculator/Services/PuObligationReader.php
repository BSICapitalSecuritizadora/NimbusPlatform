<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Enums\PuObligationCalculationState;
use App\Domain\PuCalculator\Enums\PuObligationComponent;
use App\Enums\AccessPermission;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuObligation;
use App\Models\EmissionPuObligationCalculation;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Leitura das obrigações financeiras do PU para os consumidores (Fase 5).
 *
 * Emissão governada pela curva oficial (há versão homologada ou já há
 * obrigações): o valor esperado vem só do cálculo oficial vigente, e só quando
 * ele é confiável; a liquidação e a conciliação ficam à parte e nunca são
 * publicadas. Emissão sem curva oficial: os consumidores seguem no Cronograma de
 * Pagamentos informado, como sempre.
 */
final class PuObligationReader
{
    /**
     * Componentes nas colunas do cronograma que site e relatório já conhecem. O
     * principal acelerado do vencimento antecipado não é amortização ordinária.
     *
     * @var array<string, list<PuObligationComponent>>
     */
    private const PUBLIC_COLUMNS = [
        'premium_value' => [PuObligationComponent::Premium],
        'interest_value' => [PuObligationComponent::OrdinaryInterest],
        'amortization_value' => [PuObligationComponent::OrdinaryAmortization],
        'extra_amortization_value' => [PuObligationComponent::ExtraordinaryAmortization, PuObligationComponent::AcceleratedPrincipal],
    ];

    public function isGoverned(Emission $emission): bool
    {
        return EmissionPuCurveVersion::query()->where('emission_id', $emission->id)->official()->exists()
            || EmissionPuObligation::query()->where('emission_id', $emission->id)->exists();
    }

    /**
     * Histórico de pagamentos do site a partir do esperado OFICIAL: uma linha por
     * data, só obrigações ativas, vencidas até a data e com cálculo confiável.
     * Nulo sem curva oficial vigente -- o site segue no cronograma informado, como
     * o PU segue no Histórico de PU.
     *
     * @return Collection<int, Payment>|null
     */
    public function officialPaymentHistory(Emission $emission, CarbonImmutable $through): ?Collection
    {
        if (! EmissionPuCurveVersion::query()->where('emission_id', $emission->id)->official()->exists()) {
            return null;
        }

        return EmissionPuObligation::query()
            ->where('emission_id', $emission->id)
            ->active()
            ->where('calculation_state', PuObligationCalculationState::Calculated->value)
            ->whereNotNull('current_calculation_id')
            ->whereDate('due_date', '<=', $through->toDateString())
            ->with('currentCalculation.components')
            ->orderBy('due_date')
            ->orderBy('id')
            ->get()
            ->filter(fn (EmissionPuObligation $obligation): bool => $obligation->currentCalculation?->isComplete() ?? false)
            ->groupBy(fn (EmissionPuObligation $obligation): string => CarbonImmutable::instance($obligation->due_date)->toDateString())
            ->map(function (Collection $obligations, string $date): Payment {
                $values = [];

                foreach (self::PUBLIC_COLUMNS as $column => $components) {
                    $values[$column] = $obligations->reduce(
                        fn (string $total, EmissionPuObligation $obligation): string => bcadd($total, $this->sum($obligation->currentCalculation, $components), 2),
                        '0.00',
                    );
                }

                return new Payment(['emission_id' => $obligations->first()->emission_id, 'payment_date' => $date, ...$values]);
            })
            ->values();
    }

    /**
     * Obrigações ativas da última data de pagamento até `$through` (relatório
     * mensal), com cálculo, liquidação e conciliação vigentes.
     *
     * @return EloquentCollection<int, EmissionPuObligation>
     */
    public function lastDueObligations(Emission $emission, CarbonImmutable $through): EloquentCollection
    {
        $lastDue = EmissionPuObligation::query()
            ->where('emission_id', $emission->id)
            ->active()
            ->whereDate('due_date', '<=', $through->toDateString())
            ->max('due_date');

        if ($lastDue === null) {
            return new EloquentCollection;
        }

        return EmissionPuObligation::query()
            ->where('emission_id', $emission->id)
            ->active()
            ->whereDate('due_date', CarbonImmutable::parse((string) $lastDue)->toDateString())
            ->with(['currentCalculation.components', 'activeSettlement', 'latestReconciliation'])
            ->orderBy('obligation_type')
            ->orderBy('sequence')
            ->get();
    }

    /**
     * Obrigações da emissão para quem pode ver a conciliação.
     *
     * @return EloquentCollection<int, EmissionPuObligation>
     */
    public function forEmission(Emission $emission, User $user): EloquentCollection
    {
        if (! $user->can(AccessPermission::PuReconciliationView->value)) {
            throw new AuthorizationException('Sem permissão para ver a conciliação das liquidações do PU.');
        }

        return EmissionPuObligation::query()
            ->where('emission_id', $emission->id)
            ->with(['currentCalculation.components', 'activeSettlement', 'latestReconciliation'])
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  list<PuObligationComponent>  $components
     */
    public function sum(?EmissionPuObligationCalculation $calculation, array $components): string
    {
        if (! $calculation instanceof EmissionPuObligationCalculation) {
            return '0.00';
        }

        $total = '0.00';

        foreach ($components as $component) {
            $total = bcadd($total, $calculation->componentAmount($component) ?? '0.00', 2);
        }

        return $total;
    }
}
