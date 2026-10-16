<?php

namespace App\DTOs\Measurements;

use App\Services\MeasurementFinancialReconciliationService;
use Illuminate\Contracts\Support\Arrayable;

/**
 * Se uma regra financeira cobre a divergência de um pagamento, e por quê.
 *
 * A divergência é a acumulada do empreendimento na competência -- o que a
 * família já registrou antes mais este pagamento, menos o valor aprovado --,
 * nunca a de um pagamento isolado: a regra cobre a exposição inteira, e um
 * pagamento dividido em vários não reaproveita o limite.
 *
 * - limite em reais: teto absoluto da divergência acumulada;
 * - limite percentual: percentual do **valor aprovado** do empreendimento na
 *   competência (Fundo de Obra × realizado do mês). Sem valor aprovado
 *   positivo, ele não autoriza nada -- uma exceção sobre valor aprovado zero
 *   precisa de limite em reais;
 * - os dois informados: valem os dois (o menor teto).
 *
 * Valores em string decimal, escala 2, nunca float.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class MeasurementFinancialRuleCoverage implements Arrayable
{
    public const PERCENT_BASIS_APPROVED_AMOUNT = 'approved_amount';

    public function __construct(
        public bool $covered,
        public ?string $refusal,
        public string $direction,
        public ?string $divergenceAmount,
        public ?string $referenceAmount,
        public ?string $maximumDifferenceAmount,
        public ?string $maximumDifferencePercent,
        public ?string $percentBasis,
        public ?string $percentCeilingAmount,
    ) {}

    /**
     * O teto que vale: o menor entre o limite em reais e o percentual do
     * valor aprovado, ou `null` sem limite aplicável.
     */
    public function ceilingAmount(): ?string
    {
        $ceilings = array_values(array_filter([$this->maximumDifferenceAmount, $this->percentCeilingAmount], fn (?string $value): bool => $value !== null));

        if ($ceilings === []) {
            return null;
        }

        return array_reduce($ceilings, fn (?string $lowest, string $ceiling): string => $lowest === null || bccomp($ceiling, $lowest, 2) < 0 ? $ceiling : $lowest);
    }

    /**
     * A explicação curta, como a pessoa lê no modal e no enquadramento.
     */
    public function summary(): string
    {
        $limits = array_values(array_filter([
            $this->maximumDifferenceAmount === null ? null : 'limite em reais '.MeasurementFinancialReconciliationService::formatCurrency($this->maximumDifferenceAmount),
            $this->maximumDifferencePercent === null ? null : sprintf(
                'limite de %s do valor aprovado%s',
                MeasurementFinancialReconciliationService::formatPercent(self::trimPercent($this->maximumDifferencePercent)),
                $this->percentCeilingAmount === null ? '' : ' ('.MeasurementFinancialReconciliationService::formatCurrency($this->percentCeilingAmount).')',
            ),
        ]));

        return sprintf(
            'Divergência acumulada do empreendimento: %s; %s.%s',
            MeasurementFinancialReconciliationService::formatCurrency($this->divergenceAmount),
            $limits === [] ? 'regra sem limite' : implode('; ', $limits),
            $this->covered ? ' A regra cobre a divergência.' : ' '.$this->refusal,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'covered' => $this->covered,
            'refusal' => $this->refusal,
            'direction' => $this->direction,
            'divergence_amount' => $this->divergenceAmount,
            'reference_amount' => $this->referenceAmount,
            'maximum_difference_amount' => $this->maximumDifferenceAmount,
            'maximum_difference_percent' => $this->maximumDifferencePercent,
            'percent_basis' => $this->percentBasis,
            'percent_ceiling_amount' => $this->percentCeilingAmount,
            'ceiling_amount' => $this->ceilingAmount(),
            'cumulative' => true,
        ];
    }

    /**
     * "10.0000" vira "10", "2.5000" vira "2.5": o percentual como a pessoa
     * escreve.
     */
    private static function trimPercent(string $percent): string
    {
        return str_contains($percent, '.') ? rtrim(rtrim($percent, '0'), '.') : $percent;
    }
}
