<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;
use App\Models\SalesBoardCycleBaseline;
use App\Models\SalesBoardCycleLine;
use App\Models\SalesBoardCycleMovement;

/**
 * Um snapshot com a procedência de cada linha e de cada movimento.
 *
 * A posição derivada responde "o que o Quadro dizia"; os fingerprints por linha
 * respondem "de quais fatos aquilo saiu". As duas coisas juntas são o que
 * permite dizer que a fonte da unidade 101 mudou **sem** que nenhum número dela
 * tenha mudado -- uma afirmação impossível de fazer olhando só para o resultado.
 *
 * Vale para os dois lados de qualquer comparação: uma versão gravada há três
 * meses e o que a fonte viva produziria agora chegam aqui na mesma forma, e é
 * por isso que existe um único comparador em vez de um por combinação.
 */
readonly class SalesBoardComparableSnapshot extends BaseDTO
{
    /**
     * @param  array<int, string>  $lineSourceFingerprints  indexado por `construction_unit_id`
     * @param  array<string, string>  $movementSourceFingerprints  indexado por `tipo@contrato`
     */
    public function __construct(
        public SalesBoardSnapshot $snapshot,
        public array $lineSourceFingerprints,
        public array $movementSourceFingerprints,
    ) {}

    /**
     * O lado vivo: o que a derivação produz agora, com a fonte que ela observou.
     */
    public static function fromDerived(
        SalesBoardDerivedPosition $position,
        SalesBoardSourceObservation $observation,
    ): self {
        $snapshot = SalesBoardSnapshot::fromDerivedPosition($position);

        $lineFingerprints = [];
        foreach ($snapshot->lines as $line) {
            $lineFingerprints[$line->constructionUnitId] = $observation->fingerprintForUnit($line->constructionUnitId);
        }

        $movementFingerprints = [];
        foreach ($snapshot->movements as $movement) {
            $movementFingerprints[$movement->key()] = $observation->fingerprintForMovement($movement->type, $movement->contractId);
        }

        return new self($snapshot, $lineFingerprints, $movementFingerprints);
    }

    /**
     * O lado congelado: tudo relido do que foi gravado, inclusive a procedência.
     */
    public static function fromBaseline(SalesBoardCycleBaseline $baseline): self
    {
        $baseline->loadMissing(['lines', 'movements', 'cycle']);

        $lineFingerprints = $baseline->lines
            ->mapWithKeys(fn (SalesBoardCycleLine $line): array => [
                (int) $line->construction_unit_id => (string) $line->source_fingerprint,
            ])
            ->all();

        $movementFingerprints = $baseline->movements
            ->mapWithKeys(fn (SalesBoardCycleMovement $movement): array => [
                $movement->movement_type->value.'@'.(int) $movement->contract_id => (string) $movement->source_fingerprint,
            ])
            ->all();

        return new self(
            SalesBoardSnapshot::fromBaseline($baseline),
            $lineFingerprints,
            $movementFingerprints,
        );
    }

    public function lineSourceFingerprint(int $constructionUnitId): ?string
    {
        return $this->lineSourceFingerprints[$constructionUnitId] ?? null;
    }

    public function movementSourceFingerprint(string $key): ?string
    {
        return $this->movementSourceFingerprints[$key] ?? null;
    }
}
