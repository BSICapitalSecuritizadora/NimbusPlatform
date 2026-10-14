<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexerCertification;
use App\Domain\PuCalculator\Enums\PuOperationalActivationStatus;
use App\Domain\PuCalculator\Enums\PuOperationalReadinessStatus;
use Carbon\CarbonImmutable;

/**
 * Avaliação de prontidão operacional do PU de uma emissão (Fase 7): o que o
 * sistema verificou, o que bloqueia, o que precisa de decisão humana e o que a
 * revisão contratual documentada precisa cobrir antes de uma ativação.
 *
 * Cada verificação tem resultado `pass`, `blocked`, `review` ou `info`.
 */
final readonly class PuOperationalReadinessReport
{
    public const RESULT_PASS = 'pass';

    public const RESULT_BLOCKED = 'blocked';

    public const RESULT_REVIEW = 'review';

    public const RESULT_INFO = 'info';

    /**
     * @param  list<array{key: string, label: string, result: string, details: list<string>}>  $checks
     * @param  list<string>  $contractualChecklist
     */
    public function __construct(
        public int $emissionId,
        public string $emissionName,
        public PuOperationalReadinessStatus $status,
        public PuOperationalActivationStatus $activationStatus,
        public bool $globallyEnabled,
        public ?PuIndexer $indexer,
        public ?PuIndexerCertification $indexerCertification,
        public array $checks,
        public array $contractualChecklist,
        public CarbonImmutable $evaluatedAt,
    ) {}

    /**
     * @return list<string>
     */
    public function blockers(): array
    {
        return $this->detailsWith(self::RESULT_BLOCKED);
    }

    /**
     * @return list<string>
     */
    public function reviewItems(): array
    {
        return $this->detailsWith(self::RESULT_REVIEW);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'emission_id' => $this->emissionId,
            'emission_name' => $this->emissionName,
            'status' => $this->status->value,
            'activation_status' => $this->activationStatus->value,
            'globally_enabled' => $this->globallyEnabled,
            'indexer' => $this->indexer?->value,
            'indexer_certification' => $this->indexerCertification?->value,
            'checks' => $this->checks,
            'blockers' => $this->blockers(),
            'review_items' => $this->reviewItems(),
            'contractual_checklist' => $this->contractualChecklist,
            'evaluated_at' => $this->evaluatedAt->toIso8601String(),
        ];
    }

    /**
     * @return list<string>
     */
    private function detailsWith(string $result): array
    {
        $details = [];

        foreach ($this->checks as $check) {
            if ($check['result'] === $result) {
                array_push($details, ...$check['details']);
            }
        }

        return $details;
    }
}
