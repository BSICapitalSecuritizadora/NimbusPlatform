<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;

/**
 * A diferença completa entre duas versões de uma competência.
 *
 * Serve tanto para "o que mudou da V1 para a V2" quanto para "o que mudaria se
 * eu recalculasse agora", porque os dois lados chegam na mesma forma.
 *
 * Os deltas dos baldes vêm junto com as diferenças por unidade e por movimento
 * porque as duas leituras são necessárias e nenhuma substitui a outra: o delta
 * diz o tamanho do impacto, as linhas dizem onde ele está. Um mês em que uma
 * unidade sai do estoque e outra entra tem delta zero e duas mudanças reais.
 */
readonly class SalesBoardBaselineDiff extends BaseDTO
{
    /**
     * @param  list<SalesBoardLineDiff>  $lines
     * @param  list<SalesBoardMovementDiff>  $movements
     * @param  list<SalesBoardBucketDelta>  $buckets
     */
    public function __construct(
        public array $lines,
        public array $movements,
        public array $buckets,
        public int $unitsTotalBefore,
        public int $unitsTotalAfter,
        public int $undeterminedUnitsBefore,
        public int $undeterminedUnitsAfter,
    ) {}

    public function isEmpty(): bool
    {
        return ($this->lines === []) && ($this->movements === []) && ! $this->hasBucketChange();
    }

    public function hasMaterialChange(): bool
    {
        return collect($this->lines)->contains(fn (SalesBoardLineDiff $line): bool => $line->hasMaterialChange())
            || collect($this->movements)->contains(fn (SalesBoardMovementDiff $movement): bool => $movement->hasMaterialChange())
            || $this->hasBucketChange()
            || ($this->unitsTotalBefore !== $this->unitsTotalAfter)
            || ($this->undeterminedUnitsBefore !== $this->undeterminedUnitsAfter);
    }

    public function hasBucketChange(): bool
    {
        return collect($this->buckets)->contains(fn (SalesBoardBucketDelta $bucket): bool => $bucket->hasChange());
    }

    public function undeterminedDelta(): int
    {
        return $this->undeterminedUnitsAfter - $this->undeterminedUnitsBefore;
    }

    public function unitsTotalDelta(): int
    {
        return $this->unitsTotalAfter - $this->unitsTotalBefore;
    }

    /**
     * @return list<SalesBoardBucketDelta>
     */
    public function changedBuckets(): array
    {
        return array_values(array_filter(
            $this->buckets,
            fn (SalesBoardBucketDelta $bucket): bool => $bucket->hasChange(),
        ));
    }

    /**
     * Resumo curto, para notificação e cabeçalho de tela.
     */
    public function summary(): string
    {
        if ($this->isEmpty()) {
            return 'Nenhuma diferença.';
        }

        $parts = [];

        if ($this->lines !== []) {
            $parts[] = sprintf('%d unidade(s) com alteração', count($this->lines));
        }

        if ($this->movements !== []) {
            $parts[] = sprintf('%d movimento(s) com alteração', count($this->movements));
        }

        foreach ($this->changedBuckets() as $bucket) {
            $parts[] = sprintf('%s %s un. / %s', $bucket->label, $bucket->formattedUnitsDelta(), $bucket->formattedValueDelta());
        }

        return implode(' · ', $parts);
    }

    /**
     * O diff por extenso, uma diferença por linha, cada uma presa à unidade ou
     * ao contrato que a originou.
     *
     * Vive aqui, e não na tela, porque é a resposta do domínio à pergunta "o que
     * mudou?" -- a tela apenas a exibe, e o texto continua verificável sem
     * montar um componente.
     */
    public function describe(): string
    {
        if ($this->isEmpty()) {
            return 'Nenhuma diferença.';
        }

        $lines = array_map(
            fn (SalesBoardLineDiff $line): string => sprintf('• Unidade %s — %s', $line->displayName(), $line->describe()),
            $this->lines,
        );

        $movements = array_map(
            fn (SalesBoardMovementDiff $movement): string => sprintf(
                '• %s do contrato %s — %s',
                $movement->type->label(),
                (string) ($movement->contractCode ?? '#'.$movement->contractId),
                $movement->describe(),
            ),
            $this->movements,
        );

        return implode("\n", [$this->summary(), ...$lines, ...$movements]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'units_total_before' => $this->unitsTotalBefore,
            'units_total_after' => $this->unitsTotalAfter,
            'undetermined_before' => $this->undeterminedUnitsBefore,
            'undetermined_after' => $this->undeterminedUnitsAfter,
            'buckets' => array_map(fn (SalesBoardBucketDelta $bucket): array => $bucket->toArray(), $this->buckets),
            'lines' => array_map(fn (SalesBoardLineDiff $line): array => $line->toArray(), $this->lines),
            'movements' => array_map(fn (SalesBoardMovementDiff $movement): array => $movement->toArray(), $this->movements),
        ];
    }
}
