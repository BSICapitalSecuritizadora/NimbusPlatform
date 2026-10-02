<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleBaseline;
use App\Services\SalesBoards\SalesBoardPriorPositionResolver;
use App\Services\SalesBoards\SalesBoardRecalculationService;
use App\Services\SalesBoards\SalesBoardStaleDetectionService;
use Carbon\CarbonImmutable;

/**
 * A estrutura da cadeia de uma competência: de qual competência anterior os
 * movimentos dela partem -- o ciclo âncora, e não a versão dele -- e quais
 * competências canceladas a janela dela absorve
 * ({@see SalesBoardPriorPositionResolver}).
 *
 * Reabrir ou cancelar uma competência anterior muda essa estrutura para a
 * seguinte em andamento: a janela dos movimentos, a véspera contra a qual a
 * presença das unidades é comparada (os avisos de baixa e de reativação) e a
 * ponte passam a partir de outro lugar. Quando o mês reaberto ou cancelado não
 * teve fato, nenhum número da seguinte muda e os fingerprints não enxergam a
 * diferença -- a versão continuaria com o aviso de uma baixa que agora é da
 * competência reaberta, e com a ponte contra a âncora antiga. A verificação de
 * alterações ({@see SalesBoardStaleDetectionService}) e o recálculo
 * ({@see SalesBoardRecalculationService}) comparam esta estrutura além do
 * conteúdo.
 *
 * A versão da âncora fica de fora de propósito: a retificação publicada da
 * anterior, ou um recálculo dela ainda aberta, troca a versão sem mudar a
 * cadeia, e a seguinte continua julgada pelo conteúdo -- só fica desatualizada
 * se o que ela herdou da anterior mudou.
 */
readonly class SalesBoardChainStructure extends BaseDTO
{
    /**
     * @param  list<string>  $absorbedCancelledMonths  `Y-m`, do mais recente para o mais antigo
     */
    public function __construct(
        public ?int $anchorCycleId,
        public ?CarbonImmutable $anchorMonth,
        public array $absorbedCancelledMonths,
    ) {}

    /**
     * A cadeia de uma posição derivada: com âncora, os meses cancelados entre
     * ela e a competência; sem âncora, os que a competência absorve.
     */
    public static function fromPosition(SalesBoardDerivedPosition $position): self
    {
        $anchor = $position->priorPosition;

        return new self(
            anchorCycleId: $anchor?->cycleId,
            anchorMonth: $anchor?->referenceMonth,
            absorbedCancelledMonths: array_map(
                static fn (CarbonImmutable $month): string => $month->format('Y-m'),
                $anchor?->skippedCancelledMonths ?? $position->absorbedCancelledMonths,
            ),
        );
    }

    /**
     * A cadeia que a versão registrou ao nascer -- a âncora pela versão dela
     * em `previous_competence_baseline_id` e os meses absorvidos --, ou `null`
     * quando a versão é anterior a esse registro. Uma consulta, só havendo
     * âncora: o ciclo daquela versão.
     */
    public static function fromBaseline(SalesBoardCycleBaseline $baseline): ?self
    {
        $months = $baseline->absorbedCancelledMonths();

        if ($months === null) {
            return null;
        }

        $anchor = $baseline->previous_competence_baseline_id === null ? null : SalesBoardCycle::query()
            ->whereIn('id', SalesBoardCycleBaseline::query()
                ->select('sales_board_cycle_id')
                ->whereKey($baseline->previous_competence_baseline_id))
            ->first(['id', 'reference_month']);

        return new self(
            anchorCycleId: $anchor === null ? null : (int) $anchor->getKey(),
            anchorMonth: $anchor === null ? null : CarbonImmutable::parse($anchor->reference_month->toDateString())->startOfMonth(),
            absorbedCancelledMonths: $months,
        );
    }

    /**
     * O que mudou na cadeia desde que a versão foi apurada, pronto para a
     * tela -- ou `null` quando ela continua a mesma, ou quando a versão é
     * anterior ao registro da cadeia e não há com o que comparar.
     */
    public static function changeSince(SalesBoardCycleBaseline $baseline, SalesBoardDerivedPosition $position): ?string
    {
        $frozen = self::fromBaseline($baseline);
        $live = self::fromPosition($position);

        return (($frozen === null) || $frozen->sameAs($live)) ? null : $live->describeChangeFrom($frozen);
    }

    /**
     * A mesma âncora (o ciclo) e os mesmos meses absorvidos.
     */
    public function sameAs(self $other): bool
    {
        $mine = $this->absorbedCancelledMonths;
        $theirs = $other->absorbedCancelledMonths;
        sort($mine);
        sort($theirs);

        return ($this->anchorCycleId === $other->anchorCycleId) && ($mine === $theirs);
    }

    /**
     * "A cadeia de competências mudou depois desta versão: ela foi apurada a
     * partir de 06/2026, absorvendo a competência cancelada 07/2026, e hoje a
     * competência parte de 07/2026."
     */
    public function describeChangeFrom(self $frozen): string
    {
        return sprintf(
            'A cadeia de competências mudou depois desta versão: ela foi apurada %s, e hoje a competência %s.',
            $frozen->describeAsFrozen(),
            $this->describeAsLive(),
        );
    }

    private function describeAsFrozen(): string
    {
        $origin = $this->anchorMonth === null
            ? 'sem competência anterior'
            : 'a partir de '.$this->anchorMonth->format('m/Y');

        return $this->absorbedCancelledMonths === []
            ? $origin
            : $origin.', absorvendo '.$this->absorbedLabel();
    }

    private function describeAsLive(): string
    {
        $origin = $this->anchorMonth === null
            ? 'não tem competência anterior'
            : 'parte de '.$this->anchorMonth->format('m/Y');

        return $this->absorbedCancelledMonths === []
            ? $origin
            : $origin.' e absorve '.$this->absorbedLabel();
    }

    /**
     * "a competência cancelada 07/2026" ou "as competências canceladas 08/2026
     * e 07/2026".
     */
    private function absorbedLabel(): string
    {
        $months = array_map(
            static fn (string $month): string => CarbonImmutable::createFromFormat('!Y-m', $month)->format('m/Y'),
            $this->absorbedCancelledMonths,
        );

        if (count($months) === 1) {
            return 'a competência cancelada '.$months[0];
        }

        $last = array_pop($months);

        return 'as competências canceladas '.implode(', ', $months).' e '.$last;
    }
}
