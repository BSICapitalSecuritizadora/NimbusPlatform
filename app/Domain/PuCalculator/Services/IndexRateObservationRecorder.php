<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Calculators\DailyFactorCalculator;
use App\Domain\PuCalculator\DTOs\IndexRateRecordOutcome;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Exceptions\PuRateDomainException;
use App\Models\IndexRate;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;

/**
 * Porta de entrada das observações REALIZADAS de índice (sincronização com o
 * provedor, importação de planilha).
 *
 * Uma data já registrada nunca é sobrescrita por aqui:
 *
 *  - mesmo valor e mesma origem: reimportação idempotente, nada é regravado;
 *  - mesmo valor e outra origem: a procedência existente é mantida (conflito de
 *    origem, explícito);
 *  - valor diferente: é correção de histórico -- só pelo
 *    {@see IndexRateCorrectionService}, com motivo, ator e trilha;
 *  - data ocupada por projeção: realizado não substitui projeção em silêncio.
 *
 * Validação em duas camadas, e a distinção importa:
 *
 *  - DURA (domínio financeiro e formato): valor numérico sem ambiguidade de
 *    separador, no máximo 8 casas (a escala gravada), base de capitalização
 *    1 + taxa/100 positiva para o CDI (a mesma fronteira da engine,
 *    {@see DailyFactorCalculator::assertPositiveCompoundingBase()}), número-índice
 *    positivo para o IPCA e data de observação que não esteja no futuro. Taxa
 *    negativa acima de -100% a.a. é válida. Não há teto: nenhum contrato fixa um.
 *  - BRANDA (plausibilidade): um valor dez vezes maior ou menor que a observação
 *    realizada anterior parece outra unidade (fração no lugar de percentual,
 *    taxa diária no lugar de anual, pontos-base). Não é recusado: fica retido até
 *    confirmação explícita.
 */
final class IndexRateObservationRecorder
{
    /** Escala de `index_rates.rate_value` (decimal 20,8). */
    public const VALUE_SCALE = 8;

    /** Dígitos inteiros que `decimal(20,8)` comporta. */
    private const STORAGE_INTEGER_DIGITS = 12;

    /** Salto relativo, contra a observação realizada anterior, que pede confirmação. */
    public const OUTLIER_RATIO = '10';

    public function __construct(
        private readonly DailyFactorCalculator $factors,
    ) {}

    /**
     * @param  array{source: string, source_reference?: string|null, external_series_code?: string|null, fetched_at?: DateTimeInterface|null, notes?: string|null}  $provenance
     */
    public function recordRealized(
        PuIndexer $indexer,
        CarbonImmutable $date,
        string $rawValue,
        array $provenance,
        bool $confirmOutlier = false,
        bool $dryRun = false,
    ): IndexRateRecordOutcome {
        $date = $date->startOfDay();

        try {
            $value = $this->normalizeValue($indexer, $rawValue, $date);
            $this->assertObservationDateIsNotFuture($date);
        } catch (InvalidArgumentException $exception) {
            return new IndexRateRecordOutcome(IndexRateRecordOutcome::REJECTED, $date, null, $exception->getMessage());
        }

        $existing = $this->existing($indexer, $date);

        if ($existing instanceof IndexRate) {
            return $this->classifyAgainstExisting($existing, $date, $value, (string) $provenance['source']);
        }

        $warnings = [];
        $outlier = $this->outlierWarning($indexer, $date, $value);

        if ($outlier !== null) {
            if (! $confirmOutlier) {
                return new IndexRateRecordOutcome(IndexRateRecordOutcome::NEEDS_CONFIRMATION, $date, $value, $outlier);
            }

            $warnings[] = $outlier;
        }

        if ($dryRun) {
            return new IndexRateRecordOutcome(IndexRateRecordOutcome::CREATED, $date, $value, warnings: $warnings);
        }

        try {
            IndexRate::query()->create([
                'indexer' => $indexer->value,
                'rate_date' => $date,
                'rate_value' => $value,
                'source' => (string) $provenance['source'],
                'source_reference' => $provenance['source_reference'] ?? null,
                'external_series_code' => $provenance['external_series_code'] ?? null,
                'fetched_at' => $provenance['fetched_at'] ?? null,
                'is_projected' => false,
                'projection_source' => null,
                'projection_reference_date' => null,
                'projection_policy' => null,
                'index_projection_series_id' => null,
                'notes' => $provenance['notes'] ?? null,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Outro processo registrou a mesma data entre a leitura e a gravação: a
            // unique (indexer, rate_date) decide, e a observação dele é comparada
            // com a nossa como qualquer data já registrada.
            $winner = $this->existing($indexer, $date);

            if (! $winner instanceof IndexRate) {
                throw new InvalidArgumentException(sprintf('Não foi possível registrar a observação de %s.', $date->toDateString()));
            }

            return $this->classifyAgainstExisting($winner, $date, $value, (string) $provenance['source']);
        }

        return new IndexRateRecordOutcome(IndexRateRecordOutcome::CREATED, $date, $value, warnings: $warnings);
    }

    /**
     * Valor normalizado na escala gravada, ou recusa (formato ou domínio).
     *
     * @throws InvalidArgumentException
     */
    public function normalizeValue(PuIndexer $indexer, string $rawValue, ?CarbonImmutable $date = null): string
    {
        $value = str_replace(' ', '', trim($rawValue));

        if (str_contains($value, ',') && str_contains($value, '.')) {
            throw new InvalidArgumentException(sprintf(
                'Valor "%s" ambíguo: use um único separador decimal, sem separador de milhar.',
                $rawValue,
            ));
        }

        $value = str_replace(',', '.', $value);

        if (preg_match('/^([+-]?)(\d+)(?:\.(\d+))?$/', $value, $parts) !== 1) {
            throw new InvalidArgumentException(sprintf('Valor inválido "%s".', $rawValue));
        }

        $integerDigits = ltrim($parts[2], '0');
        $fraction = rtrim($parts[3] ?? '', '0');

        if (strlen($fraction) > self::VALUE_SCALE) {
            throw new InvalidArgumentException(sprintf(
                'Valor "%s" tem mais de %d casas decimais; a gravação perderia precisão.',
                $rawValue,
                self::VALUE_SCALE,
            ));
        }

        if (strlen($integerDigits) > self::STORAGE_INTEGER_DIGITS) {
            throw new InvalidArgumentException(sprintf('Valor "%s" excede o tamanho gravável.', $rawValue));
        }

        $normalized = bcadd($value, '0', self::VALUE_SCALE);

        match ($indexer) {
            // Mesma fronteira da engine: o fator só existe com 1 + taxa/100 > 0.
            PuIndexer::Cdi => $this->factors->assertPositiveCompoundingBase(
                $normalized,
                bcadd('1', bcdiv($normalized, '100', 16), 16),
            ),
            PuIndexer::Ipca => bccomp($normalized, '0', self::VALUE_SCALE) === 1
                ? null
                : throw PuRateDomainException::nonPositiveIndexNumber($date?->format('Y-m') ?? 'mês informado', $normalized),
            default => throw new InvalidArgumentException(sprintf('O indexador %s não tem série de índice.', $indexer->value)),
        };

        return $normalized;
    }

    /**
     * Observação realizada não existe no futuro: o dia de negócio de hoje é o
     * limite (o CDI do dia é divulgado no próprio dia, à noite).
     *
     * @throws InvalidArgumentException
     */
    public function assertObservationDateIsNotFuture(CarbonImmutable $date): void
    {
        $today = BusinessTime::dateString();

        if ($date->toDateString() > $today) {
            throw new InvalidArgumentException(sprintf(
                'A data %s é futura (hoje é %s): taxa futura não é observação realizada, e o cálculo realizado nunca a usa.',
                $date->toDateString(),
                $today,
            ));
        }
    }

    private function classifyAgainstExisting(IndexRate $existing, CarbonImmutable $date, string $value, string $source): IndexRateRecordOutcome
    {
        if ($existing->isProjectedRate()) {
            return new IndexRateRecordOutcome(
                IndexRateRecordOutcome::NATURE_CONFLICT,
                $date,
                $value,
                sprintf('A data %s já tem um valor PROJETADO; a observação realizada não o substitui sem decisão explícita.', $date->toDateString()),
                $existing,
            );
        }

        if (bccomp((string) $existing->rate_value, $value, self::VALUE_SCALE) !== 0) {
            return new IndexRateRecordOutcome(
                IndexRateRecordOutcome::VALUE_CONFLICT,
                $date,
                $value,
                sprintf(
                    'A data %s já tem %s (origem %s); um valor diferente é correção de histórico e só entra por "php artisan pu:index-rates:correct", com motivo.',
                    $date->toDateString(),
                    (string) $existing->rate_value,
                    (string) $existing->source,
                ),
                $existing,
            );
        }

        if ((string) $existing->source !== $source) {
            return new IndexRateRecordOutcome(
                IndexRateRecordOutcome::SOURCE_CONFLICT,
                $date,
                $value,
                sprintf(
                    'A data %s já está registrada com o mesmo valor pela origem %s; a procedência existente foi mantida.',
                    $date->toDateString(),
                    (string) $existing->source,
                ),
                $existing,
            );
        }

        return new IndexRateRecordOutcome(IndexRateRecordOutcome::UNCHANGED, $date, $value, existing: $existing);
    }

    private function outlierWarning(PuIndexer $indexer, CarbonImmutable $date, string $value): ?string
    {
        $previous = IndexRate::query()
            ->forIndexer($indexer)
            ->where('is_projected', false)
            ->whereDate('rate_date', '<', $date->toDateString())
            ->orderByDesc('rate_date')
            ->first();

        if (! $previous instanceof IndexRate) {
            return null;
        }

        $previousMagnitude = ltrim((string) $previous->rate_value, '-');
        $magnitude = ltrim($value, '-');

        if (bccomp($previousMagnitude, '0', self::VALUE_SCALE) === 0 || bccomp($magnitude, '0', self::VALUE_SCALE) === 0) {
            return null;
        }

        $ratio = bcdiv($magnitude, $previousMagnitude, 16);
        $inverse = bcdiv($previousMagnitude, $magnitude, 16);

        if (bccomp($ratio, self::OUTLIER_RATIO, 16) < 0 && bccomp($inverse, self::OUTLIER_RATIO, 16) < 0) {
            return null;
        }

        return sprintf(
            'O valor %s em %s difere %sx ou mais da observação realizada anterior (%s em %s): possível erro de unidade (fração × percentual, taxa diária × anual, pontos-base). Confirme explicitamente para registrar.',
            $value,
            $date->toDateString(),
            self::OUTLIER_RATIO,
            (string) $previous->rate_value,
            $previous->rate_date?->toDateString(),
        );
    }

    private function existing(PuIndexer $indexer, CarbonImmutable $date): ?IndexRate
    {
        return IndexRate::query()
            ->forIndexer($indexer)
            ->whereDate('rate_date', $date->toDateString())
            ->first();
    }
}
