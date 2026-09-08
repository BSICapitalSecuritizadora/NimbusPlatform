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
    ) {}

    public function isStale(): bool
    {
        return $this->impact->isStale();
    }

    public function isBlocking(): bool
    {
        return $this->impact === SalesBoardStaleImpact::Blocking;
    }

    /**
     * Mensagem pronta para a tela, já com o tamanho da diferença.
     */
    public function message(): string
    {
        return match ($this->impact) {
            SalesBoardStaleImpact::None => 'Sem alterações: a fonte continua exatamente igual à que produziu esta versão.',
            SalesBoardStaleImpact::SourceOnly => 'Fonte alterada sem impacto na posição. '.$this->diff->summary(),
            SalesBoardStaleImpact::Material => 'Alterações materiais detectadas. '.$this->diff->summary(),
            SalesBoardStaleImpact::Blocking => 'A fonte atual não está pronta para uma nova versão: '
                .implode('; ', array_map(
                    fn (string $code, int $count): string => $code.' ('.$count.')',
                    array_keys($this->readiness->blockingIssueCounts()),
                    array_values($this->readiness->blockingIssueCounts()),
                )),
        };
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
            'observed_source_fingerprint' => $this->observedSourceFingerprint,
            'observed_snapshot_fingerprint' => $this->observedSnapshotFingerprint,
            'message' => $this->message(),
            'diff' => $this->diff->toArray(),
        ];
    }
}
