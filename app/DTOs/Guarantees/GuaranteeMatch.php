<?php

declare(strict_types=1);

namespace App\DTOs\Guarantees;

use App\DTOs\BaseDTO;
use App\Enums\GuaranteeMatchLevel;
use App\Models\Guarantee;

/**
 * Correspondência entre uma garantia detectada e uma já cadastrada, com o que
 * sustenta e o que contradiz a hipótese (§7 do escopo).
 *
 * As evidências não são enfeite de tela: são o que permite ao revisor discordar
 * do sistema com base em algo verificável, em vez de aceitar um percentual.
 */
readonly class GuaranteeMatch extends BaseDTO
{
    /**
     * @param  list<string>  $evidence  o que aproxima as duas garantias
     * @param  list<string>  $contradictions  o que as afasta
     */
    public function __construct(
        public Guarantee $guarantee,
        public float $score,
        public GuaranteeMatchLevel $level,
        public array $evidence = [],
        public array $contradictions = [],
    ) {}

    public function suggestsConsolidation(): bool
    {
        return $this->level->suggestsConsolidation();
    }

    /**
     * @return array{guarantee_id: int, score: float, level: string, evidence: list<string>, contradictions: list<string>}
     */
    public function toArray(): array
    {
        return [
            'guarantee_id' => (int) $this->guarantee->getKey(),
            'score' => round($this->score, 4),
            'level' => $this->level->value,
            'evidence' => $this->evidence,
            'contradictions' => $this->contradictions,
        ];
    }
}
