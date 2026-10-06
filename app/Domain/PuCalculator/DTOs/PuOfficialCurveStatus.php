<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

use App\Domain\PuCalculator\Enums\PuOfficialCurveFreshness;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Fronteira realizada da curva oficial e o diagnóstico de atualidade.
 *
 *  - `realizedThrough`: última data da curva oficial gravada. Toda linha gravada é
 *    sustentada por observação realizada (a engine para na primeira que falta),
 *    então esta é a fronteira realizada da curva -- nunca o vencimento, nunca a
 *    data de hoje;
 *  - `expectedRealizedThrough`: até onde a curva chegaria com o índice realizado
 *    que JÁ existe no banco, pela mesma regra de busca e calendário da engine;
 *  - `nextRequiredRateDate`: a observação que impede avançar além da fronteira;
 *  - `latestRealizedRateDate`: a observação realizada mais recente do indexador;
 *  - `expectedLatestRateDate`: a observação mais recente que já deveria ter sido
 *    divulgada a esta altura (calendário de divulgação + defasagem configurada);
 *  - `reprocessingFrom`: com reprocessamento necessário, a primeira data cujo
 *    valor gravado deixou de ser reproduzível (índice corrigido, recálculo que
 *    diverge). Nula quando a causa não diz de onde -- e aí nenhuma linha vale.
 */
final readonly class PuOfficialCurveStatus
{
    public function __construct(
        public PuOfficialCurveFreshness $freshness,
        public ?int $versionId = null,
        public ?string $calculationVersion = null,
        public ?CarbonImmutable $realizedThrough = null,
        public ?CarbonImmutable $expectedRealizedThrough = null,
        public ?CarbonImmutable $curveEndDate = null,
        public ?CarbonImmutable $nextRequiredRateDate = null,
        public ?CarbonImmutable $latestRealizedRateDate = null,
        public ?CarbonImmutable $expectedLatestRateDate = null,
        public ?string $reason = null,
        public ?CarbonImmutable $reprocessingFrom = null,
    ) {}

    /**
     * O valor gravado na data ainda responde pela curva oficial.
     *
     * Só o reprocessamento põe em dúvida o que já está gravado: a curva continua
     * homologada e imutável, mas a partir de `reprocessingFrom` o recálculo com o
     * dado de hoje não chega ao mesmo número, e nenhum consumidor oficial deve
     * repeti-lo. Antes dessa data o trecho foi provado igual. Atrasada, índice
     * ausente e extensão falhou dizem até onde a curva chega, não que o gravado
     * esteja errado.
     */
    public function isReliableAt(CarbonInterface $date): bool
    {
        if ($this->freshness !== PuOfficialCurveFreshness::ReprocessingRequired) {
            return true;
        }

        return $this->reprocessingFrom !== null
            && $date->toDateString() < $this->reprocessingFrom->toDateString();
    }

    /**
     * A data está dentro da fronteira realizada da curva oficial.
     */
    public function covers(CarbonInterface $date): bool
    {
        return $this->realizedThrough !== null
            && $date->toDateString() <= $this->realizedThrough->toDateString();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'freshness' => $this->freshness->value,
            'version_id' => $this->versionId,
            'calculation_version' => $this->calculationVersion,
            'realized_through' => $this->realizedThrough?->toDateString(),
            'expected_realized_through' => $this->expectedRealizedThrough?->toDateString(),
            'curve_end_date' => $this->curveEndDate?->toDateString(),
            'next_required_rate_date' => $this->nextRequiredRateDate?->toDateString(),
            'latest_realized_rate_date' => $this->latestRealizedRateDate?->toDateString(),
            'expected_latest_rate_date' => $this->expectedLatestRateDate?->toDateString(),
            'reason' => $this->reason,
            'reprocessing_from' => $this->reprocessingFrom?->toDateString(),
        ];
    }
}
