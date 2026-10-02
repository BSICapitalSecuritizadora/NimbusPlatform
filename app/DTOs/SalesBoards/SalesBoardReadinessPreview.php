<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;
use Carbon\CarbonImmutable;

/**
 * A prévia de prontidão de uma Emissão numa competência: o que a automação
 * encontraria se apurasse agora, empreendimento a empreendimento.
 *
 * Só agregados. Nenhuma linha de unidade, nenhum contrato, nenhum dado de
 * comprador: a tela precisa dizer se a competência pode ser congelada e o que
 * falta, e para isso bastam a situação, as contagens por balde e os códigos de
 * bloqueio e de aviso com onde se corrigem. É o que torna seguro guardar o
 * resultado numa propriedade do Livewire, que vai e volta pelo navegador.
 */
readonly class SalesBoardReadinessPreview extends BaseDTO
{
    /**
     * @param  list<array{id: int, name: string, ready: bool, cycle: array{id: int, status: string}|null, blockers: list<array{code: string, label: string, hint: string|null, count: int|null}>, warnings: list<array{code: string, label: string, hint: string|null, count: int|null}>, buckets: array{stock: int, financed: int, settled: int, exchanged: int, undetermined: int, total: int}}>  $constructions
     * @param  list<array{board_id: int, construction: string, reference_month: string, board_emission_id: int, construction_emission_id: int|null}>  $misplacedBoards
     * @param  list<array{construction: string, reference_month: string, construction_emission_id: int|null, board_emission_ids: string, board_ids: string}>  $duplicatedCompetences
     */
    public function __construct(
        public int $emissionId,
        public string $emissionName,
        public CarbonImmutable $referenceMonth,
        public CarbonImmutable $positionDate,
        public CarbonImmutable $dueDate,
        public bool $competenceClosed,
        public bool $automationCovers,
        public bool $emissionLiquidated,
        public array $constructions,
        public array $misplacedBoards,
        public array $duplicatedCompetences,
        public CarbonImmutable $calculatedAt,
    ) {}

    public function readyCount(): int
    {
        return count(array_filter($this->constructions, fn (array $construction): bool => $construction['ready']));
    }

    /**
     * O formato que a tela guarda: só escalares e listas, serializável pelo
     * Livewire.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'emission_id' => $this->emissionId,
            'emission_name' => $this->emissionName,
            'reference_month' => $this->referenceMonth->format('m/Y'),
            'position_date' => $this->positionDate->format('d/m/Y'),
            'due_date' => $this->dueDate->format('d/m/Y'),
            'competence_closed' => $this->competenceClosed,
            'automation_covers' => $this->automationCovers,
            'emission_liquidated' => $this->emissionLiquidated,
            'constructions' => $this->constructions,
            'ready_count' => $this->readyCount(),
            'misplaced_boards' => $this->misplacedBoards,
            'duplicated_competences' => $this->duplicatedCompetences,
            'calculated_at' => $this->calculatedAt->format('d/m/Y H:i'),
        ];
    }
}
