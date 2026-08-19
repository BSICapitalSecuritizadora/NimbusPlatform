<?php

declare(strict_types=1);

namespace App\DTOs\Guarantees;

use App\DTOs\BaseDTO;
use App\Enums\GuaranteeReconciliationOutcome;
use App\Models\Fund;
use App\Models\Guarantee;

/**
 * O que exatamente acontecerá se o revisor mandar complementar (§9 do escopo).
 *
 * A tela de revisão mostra este plano antes de qualquer escrita, e o serviço
 * aplica o mesmo objeto — o impacto exibido e o impacto executado são a mesma
 * coisa, e não duas leituras que podem divergir.
 */
readonly class GuaranteeConsolidationPlan extends BaseDTO
{
    /**
     * @param  list<GuaranteeFieldDelta>  $complements  campos vazios que passam a ter valor
     * @param  list<GuaranteeFieldDelta>  $confirmations  campos que o documento apenas repete
     * @param  list<GuaranteeFieldDelta>  $divergences  campos cujo valor documental difere do vigente
     */
    public function __construct(
        public ?Guarantee $guarantee,
        public ?GuaranteeMatch $match,
        public GuaranteeReconciliationOutcome $outcome,
        public array $complements = [],
        public array $confirmations = [],
        public array $divergences = [],
        /** Fundo já cadastrado cuja conta bancária é a mesma do documento (§16). */
        public ?Fund $linkedFund = null,
        /** O documento é a primeira fonte documental desta garantia (§13). */
        public bool $providesFirstDocumentarySource = false,
    ) {}

    public function hasGuarantee(): bool
    {
        return $this->guarantee !== null;
    }

    public function hasComplements(): bool
    {
        return $this->complements !== [];
    }

    public function hasDivergences(): bool
    {
        return $this->divergences !== [];
    }

    /**
     * Há algo além de uma nova fonte documental a registrar?
     *
     * Quando não há, complementar continua valendo a pena: a garantia ganha a
     * cláusula e a página que faltavam, sem mexer em nenhum valor.
     */
    public function changesAnyValue(): bool
    {
        return $this->hasComplements() || $this->hasDivergences();
    }

    /**
     * @return list<GuaranteeFieldDelta>
     */
    public function allDeltas(): array
    {
        return array_merge($this->complements, $this->divergences, $this->confirmations);
    }

    public function deltaFor(string $field): ?GuaranteeFieldDelta
    {
        foreach ($this->allDeltas() as $delta) {
            if ($delta->field === $field) {
                return $delta;
            }
        }

        return null;
    }

    /**
     * Resumo textual das alterações, no formato usado pela auditoria (§20).
     *
     * @return list<string>
     */
    public function summaryLines(): array
    {
        $lines = [];

        foreach ($this->complements as $delta) {
            $lines[] = sprintf('+ %s: %s', $delta->label, $delta->newDisplay);
        }

        foreach ($this->divergences as $delta) {
            $lines[] = sprintf('~ %s: %s → %s', $delta->label, $delta->currentDisplay, $delta->newDisplay);
        }

        return $lines;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'guarantee_id' => $this->guarantee?->getKey(),
            'outcome' => $this->outcome->value,
            'match' => $this->match?->toArray(),
            'complements' => array_map(static fn (GuaranteeFieldDelta $delta): array => $delta->toArray(), $this->complements),
            'confirmations' => array_map(static fn (GuaranteeFieldDelta $delta): array => $delta->toArray(), $this->confirmations),
            'divergences' => array_map(static fn (GuaranteeFieldDelta $delta): array => $delta->toArray(), $this->divergences),
            'linked_fund_id' => $this->linkedFund?->getKey(),
            'provides_first_documentary_source' => $this->providesFirstDocumentarySource,
        ];
    }
}
