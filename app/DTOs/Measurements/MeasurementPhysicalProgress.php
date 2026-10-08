<?php

namespace App\DTOs\Measurements;

use App\Support\Money\IntegerMoney;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Support\Arrayable;

/**
 * Progresso físico de um plano de medição -- uma obra dentro de uma operação.
 *
 * A regra inteira fica aqui para que Engenharia, cronograma, modal e relatório
 * não somem cada um do seu jeito:
 *
 *     atual = avanço inicial do plano + Σ avanço das medições com Engenharia vigente
 *
 * Tudo em basis points inteiros (100,00% = 10.000), sem float. Um mês sem
 * medição não tem contribuição -- não "contribui com zero" --, então o
 * acumulado dele é o último conhecido e nunca regride. Uma aprovação que deixou
 * de valer simplesmente não aparece em `$contributions`: o histórico continua
 * nas linhas, nas revisões e na trilha, mas não soma.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class MeasurementPhysicalProgress implements Arrayable
{
    public const LIMIT_BASIS_POINTS = IntegerMoney::BASIS_POINTS_SCALE;

    /**
     * @param  list<MeasurementPhysicalProgressContribution>  $contributions  ordenadas pela posição no cronograma (competência, sequência)
     * @param  list<int>  $unverifiedMeasurementIds  medições com Engenharia vigente cujo avanço para este plano não pôde ser lido
     */
    public function __construct(
        public int $planSetId,
        public int $initialBasisPoints,
        public ?CarbonImmutable $initialReferenceDate,
        public array $contributions,
        public array $unverifiedMeasurementIds = [],
    ) {}

    public function measuredBasisPoints(): int
    {
        return array_sum(array_map(
            fn (MeasurementPhysicalProgressContribution $contribution): int => $contribution->basisPoints,
            $this->contributions,
        ));
    }

    public function currentBasisPoints(): int
    {
        return $this->initialBasisPoints + $this->measuredBasisPoints();
    }

    public function remainingBasisPoints(): int
    {
        return max(0, self::LIMIT_BASIS_POINTS - $this->currentBasisPoints());
    }

    /**
     * Exatamente 100,00% é válido; qualquer coisa acima, não. Um mês sem avanço
     * (0%) sempre cabe -- inclusive num plano que já chegou a 100% --, para que
     * a obra concluída não trave a medição dos outros empreendimentos da
     * operação.
     */
    public function exceedsLimitWith(int $basisPoints): bool
    {
        return $basisPoints > $this->remainingBasisPoints();
    }

    /**
     * A competência termina até a data a que o avanço inicial se refere: o que
     * foi executado nela já está dentro do avanço inicial, e medi-la de novo
     * contaria o mesmo trabalho duas vezes.
     */
    public function initialProgressCovers(CarbonInterface $competence): bool
    {
        return $this->initialBasisPoints > 0
            && $this->initialReferenceDate !== null
            && $competence->copy()->endOfMonth()->toDateString() <= $this->initialReferenceDate->toDateString();
    }

    /**
     * Há algo conhecido sobre o avanço até a data: uma medição vigente ou o
     * avanço inicial já em vigor nela. Antes disso, não medido não é 0%.
     */
    public function isKnownThroughDate(CarbonInterface $date): bool
    {
        $limit = $date->toDateString();

        if ($this->initialReferenceDate !== null) {
            if ($this->initialReferenceDate->toDateString() <= $limit) {
                return true;
            }
        } elseif ($this->initialBasisPoints > 0) {
            return true;
        }

        foreach ($this->contributions as $contribution) {
            if ($contribution->measurementDate !== null && $contribution->measurementDate->toDateString() <= $limit) {
                return true;
            }
        }

        return false;
    }

    /**
     * A recusa do teto, igual no modal e na aprovação: quanto já há, quanto foi
     * informado e quanto ainda cabe.
     */
    public function limitExceededMessage(string $label, int $basisPoints): string
    {
        return sprintf(
            'O percentual físico informado para %s ultrapassaria o limite de 100%% do empreendimento. Progresso atual: %s. Percentual informado: %s. Máximo restante: %s.',
            $label,
            self::format($this->currentBasisPoints()),
            self::format($basisPoints),
            self::format($this->remainingBasisPoints()),
        );
    }

    public function isVerifiable(): bool
    {
        return $this->unverifiedMeasurementIds === [];
    }

    public function hasMeasurements(): bool
    {
        return $this->contributions !== [];
    }

    /**
     * Acumulado numa posição do cronograma: o avanço inicial mais tudo o que foi
     * medido até ela, inclusive. A posição é a competência e, no mesmo mês, a
     * sequência -- a competência é o eixo do domínio (a linha aprovada precisa
     * ser do mês da medição); a sequência é só a ordem em que as linhas foram
     * cadastradas.
     */
    public function cumulativeThroughPosition(?CarbonInterface $measurementDate, int $sequenceNumber): int
    {
        $position = self::position($measurementDate, $sequenceNumber);

        return $this->initialBasisPoints + array_sum(array_map(
            fn (MeasurementPhysicalProgressContribution $contribution): int => self::position($contribution->measurementDate, $contribution->sequenceNumber) <= $position
                ? $contribution->basisPoints
                : 0,
            $this->contributions,
        ));
    }

    /**
     * A posição está entre a primeira e a última medição vigente do plano:
     * antes da primeira e depois da última não há o que afirmar sobre o
     * acumulado daquela linha.
     */
    public function isWithinMeasuredRange(?CarbonInterface $measurementDate, int $sequenceNumber): bool
    {
        if ($this->contributions === []) {
            return false;
        }

        $first = $this->contributions[0];
        $last = $this->contributions[array_key_last($this->contributions)];
        $position = self::position($measurementDate, $sequenceNumber);

        return self::position($first->measurementDate, $first->sequenceNumber) <= $position
            && $position <= self::position($last->measurementDate, $last->sequenceNumber);
    }

    /**
     * Chave de ordenação de uma posição do cronograma: competência, depois sequência.
     *
     * @return array{0: string, 1: int}
     */
    public static function position(?CarbonInterface $measurementDate, int $sequenceNumber): array
    {
        return [$measurementDate?->toDateString() ?? '', $sequenceNumber];
    }

    /**
     * Acumulado conhecido numa data. O avanço inicial só entra a partir da data
     * a que ele se refere; sem data (plano anterior a este campo), sempre entra.
     */
    public function cumulativeThroughDate(CarbonInterface $date): int
    {
        $limit = $date->toDateString();
        $initial = $this->initialReferenceDate === null || $this->initialReferenceDate->toDateString() <= $limit
            ? $this->initialBasisPoints
            : 0;

        return $initial + array_sum(array_map(
            fn (MeasurementPhysicalProgressContribution $contribution): int => $contribution->measurementDate !== null
                && $contribution->measurementDate->toDateString() <= $limit ? $contribution->basisPoints : 0,
            $this->contributions,
        ));
    }

    /**
     * @return list<MeasurementPhysicalProgressContribution>
     */
    public function contributionsWithin(CarbonInterface $from, CarbonInterface $until): array
    {
        $start = $from->toDateString();
        $end = $until->toDateString();

        return array_values(array_filter(
            $this->contributions,
            fn (MeasurementPhysicalProgressContribution $contribution): bool => $contribution->measurementDate !== null
                && $contribution->measurementDate->toDateString() >= $start
                && $contribution->measurementDate->toDateString() <= $end,
        ));
    }

    /**
     * @return list<MeasurementPhysicalProgressContribution>
     */
    public function contributionsForLine(int $planLineId): array
    {
        return $this->contributionsForLines([$planLineId]);
    }

    /**
     * Contribuições de qualquer das linhas -- a mesma medição prevista nas
     * várias versões do plano tem uma linha em cada uma.
     *
     * @param  list<int>  $planLineIds
     * @return list<MeasurementPhysicalProgressContribution>
     */
    public function contributionsForLines(array $planLineIds): array
    {
        return array_values(array_filter(
            $this->contributions,
            fn (MeasurementPhysicalProgressContribution $contribution): bool => $contribution->planLineId !== null
                && in_array($contribution->planLineId, $planLineIds, true),
        ));
    }

    /**
     * Medição com Engenharia vigente que já ocupa a linha, se houver.
     */
    public function lineClaimant(int $planLineId): ?int
    {
        return $this->lineClaimantAmong([$planLineId]);
    }

    /**
     * Medição com Engenharia vigente que já ocupa alguma das linhas -- a
     * linhagem inteira da medição prevista.
     *
     * @param  list<int>  $planLineIds
     */
    public function lineClaimantAmong(array $planLineIds): ?int
    {
        return $this->contributionsForLines($planLineIds)[0]->measurementId ?? null;
    }

    /**
     * Contribuições da medição prevista em qualquer versão do plano: a linha
     * medida na V1 e a cópia dela na V2 são a mesma linhagem.
     *
     * @return list<MeasurementPhysicalProgressContribution>
     */
    public function contributionsForLineage(string $lineageKey): array
    {
        return array_values(array_filter(
            $this->contributions,
            fn (MeasurementPhysicalProgressContribution $contribution): bool => $contribution->lineageKey === $lineageKey,
        ));
    }

    /**
     * Medição com Engenharia vigente que já mediu a medição prevista, em
     * qualquer versão do plano.
     */
    public function lineageClaimant(string $lineageKey): ?int
    {
        return $this->contributionsForLineage($lineageKey)[0]->measurementId ?? null;
    }

    public function initialPercent(): string
    {
        return self::decimal($this->initialBasisPoints);
    }

    public function measuredPercent(): string
    {
        return self::decimal($this->measuredBasisPoints());
    }

    public function currentPercent(): string
    {
        return self::decimal($this->currentBasisPoints());
    }

    public function remainingPercent(): string
    {
        return self::decimal($this->remainingBasisPoints());
    }

    /**
     * Percentual informado em basis points, sem float; `null` para formato
     * inválido, mais de duas casas decimais ou magnitude absurda -- um número
     * com dezenas de dígitos estouraria o inteiro na conversão e viraria erro
     * em vez de mensagem.
     */
    public static function basisPoints(mixed $value): ?int
    {
        if (is_int($value)) {
            return abs($value) < 1_000_000 ? IntegerMoney::basisPoints($value) : null;
        }

        if (is_float($value)) {
            return is_finite($value) && abs($value) < 1_000_000 ? IntegerMoney::basisPoints($value) : null;
        }

        if (! is_string($value) || preg_match('/^\s*[+-]?\d{7,}/', $value) === 1) {
            return null;
        }

        return IntegerMoney::basisPoints($value);
    }

    /**
     * Basis points como a string decimal que as colunas `decimal(…, 2)` guardam: 3550 → '35.50'.
     */
    public static function decimal(int $basisPoints): string
    {
        return IntegerMoney::decimalString($basisPoints);
    }

    /**
     * Basis points para leitura em pt-BR: 3550 → '35,50%'.
     */
    public static function format(int $basisPoints): string
    {
        return IntegerMoney::formatBasisPoints($basisPoints).'%';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'plan_set_id' => $this->planSetId,
            'initial_percent' => $this->initialPercent(),
            'initial_reference_date' => $this->initialReferenceDate?->toDateString(),
            'measured_percent' => $this->measuredPercent(),
            'current_percent' => $this->currentPercent(),
            'remaining_percent' => $this->remainingPercent(),
            'unverified_measurement_ids' => $this->unverifiedMeasurementIds,
            'contributions' => array_map(
                fn (MeasurementPhysicalProgressContribution $contribution): array => $contribution->toArray(),
                $this->contributions,
            ),
        ];
    }
}
