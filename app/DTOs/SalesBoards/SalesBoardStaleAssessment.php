<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;
use App\Enums\SalesBoardStaleImpact;
use App\Models\SalesBoardCycleBaseline;

/**
 * O veredito de uma verificação de alterações.
 *
 * Traz as duas respostas separadas -- a fonte mudou? o snapshot mudou? -- porque
 * elas não são a mesma pergunta, e o diff que localiza a diferença. Verificar
 * **nunca** recalcula: descobrir que o mundo mudou e reescrever a versão no
 * mesmo movimento apagaria justamente o que se queria comparar.
 *
 * `chainChange` é a terceira resposta: a cadeia de competências de onde a
 * versão parte mudou ({@see SalesBoardChainStructure}) -- uma competência
 * anterior foi reaberta ou cancelada. Vem pronta para a tela, e é `null`
 * quando a cadeia continua a mesma ou a versão é anterior ao registro dela.
 */
readonly class SalesBoardStaleAssessment extends BaseDTO
{
    public function __construct(
        public SalesBoardCycleBaseline $baseline,
        public SalesBoardStaleImpact $impact,
        public bool $sourceChanged,
        public bool $snapshotChanged,
        public string $observedSourceFingerprint,
        public ?string $observedSnapshotFingerprint,
        public SalesBoardReadinessReport $readiness,
        public SalesBoardBaselineDiff $diff,
        public ?string $chainChange = null,
    ) {}

    public function isStale(): bool
    {
        return $this->impact->isStale();
    }

    public function isBlocking(): bool
    {
        return $this->impact === SalesBoardStaleImpact::Blocking;
    }

    public function chainChanged(): bool
    {
        return $this->chainChange !== null;
    }

    /**
     * Mensagem pronta para a tela, já com o tamanho da diferença.
     */
    public function message(): string
    {
        return match ($this->impact) {
            SalesBoardStaleImpact::None => 'Sem alterações: a fonte continua exatamente igual à que produziu esta versão.',
            SalesBoardStaleImpact::SourceOnly => 'Fonte alterada sem impacto na posição. '.$this->diff->summary(),
            SalesBoardStaleImpact::Material => 'Alterações materiais detectadas. '.$this->materialDetail(),
            SalesBoardStaleImpact::Blocking => 'A fonte atual não está pronta para uma nova versão: '
                .implode('; ', array_map(
                    fn (string $code, int $count): string => $code.' ('.$count.')',
                    array_keys($this->readiness->blockingIssueCounts()),
                    array_values($this->readiness->blockingIssueCounts()),
                )),
        };
    }

    /**
     * A diferença material: a da cadeia, quando houver, e a do conteúdo. Com a
     * cadeia mudada e nenhum número diferente, diz isso -- "Nenhuma diferença"
     * logo depois de "Alterações materiais" pareceria contradição.
     */
    private function materialDetail(): string
    {
        if ($this->chainChange === null) {
            return $this->diff->summary();
        }

        return $this->chainChange.' '.($this->diff->isEmpty()
            ? 'Os números da posição são os mesmos, mas a janela dos movimentos, os avisos e a ponte precisam ser apurados na cadeia atual: recalcule a competência.'
            : $this->diff->summary());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'baseline_id' => $this->baseline->getKey(),
            'version' => $this->baseline->version,
            'impact' => $this->impact->value,
            'is_stale' => $this->isStale(),
            'source_changed' => $this->sourceChanged,
            'snapshot_changed' => $this->snapshotChanged,
            'chain_change' => $this->chainChange,
            'observed_source_fingerprint' => $this->observedSourceFingerprint,
            'observed_snapshot_fingerprint' => $this->observedSnapshotFingerprint,
            'message' => $this->message(),
            'diff' => $this->diff->toArray(),
        ];
    }
}
