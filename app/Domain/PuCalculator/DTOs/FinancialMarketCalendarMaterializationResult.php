<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

/**
 * Resultado de uma materialização anual do calendário financeiro consolidado.
 *
 * Um resultado bloqueado (`blocked = true`) nunca acompanha escrita alguma: o ano é materializado por
 * inteiro ou não é materializado. As razões do bloqueio vêm em `blockingReasons`, e as datas que exigem
 * revisão humana em `blockedDates`, para que o operador saiba exatamente o que resolver.
 *
 * As categorias são mutuamente exclusivas e somam `totalDays`: um feriado que cai no fim de semana é
 * contado como feriado financeiro, não como fim de semana, porque é a decisão mais informativa.
 */
final class FinancialMarketCalendarMaterializationResult
{
    /**
     * @param  list<string>  $blockingReasons
     * @param  list<array{date:string,status:string,status_label:string,evidence:list<string>}>  $blockedDates
     * @param  array<string, mixed>  $coverage
     */
    public function __construct(
        public readonly string $calendarCode,
        public readonly int $year,
        public readonly bool $dryRun,
        public bool $blocked = false,
        public array $blockingReasons = [],
        public array $blockedDates = [],
        public int $totalDays = 0,
        public int $businessDays = 0,
        public int $nonBusinessDays = 0,
        public int $weekendDays = 0,
        public int $financialHolidays = 0,
        public int $conflicts = 0,
        public int $sourceOnly = 0,
        public int $unknown = 0,
        public int $specialHoursObserved = 0,
        public int $rowsCreated = 0,
        public int $rowsUpdated = 0,
        public int $rowsUnchanged = 0,
        public int $rowsPreservedByOverride = 0,
        public array $coverage = [],
        public ?string $yearStatus = null,
        public int $revision = 0,
        public ?string $checksum = null,
    ) {}

    public function block(string $reason): void
    {
        $this->blocked = true;

        if (! in_array($reason, $this->blockingReasons, true)) {
            $this->blockingReasons[] = $reason;
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'calendar_code' => $this->calendarCode,
            'year' => $this->year,
            'dry_run' => $this->dryRun,
            'blocked' => $this->blocked,
            'blocking_reasons' => $this->blockingReasons,
            'blocked_dates' => $this->blockedDates,
            'total_days' => $this->totalDays,
            'business_days' => $this->businessDays,
            'non_business_days' => $this->nonBusinessDays,
            'weekend_days' => $this->weekendDays,
            'financial_holidays' => $this->financialHolidays,
            'conflicts' => $this->conflicts,
            'source_only' => $this->sourceOnly,
            'unknown' => $this->unknown,
            'special_hours_observed' => $this->specialHoursObserved,
            'rows_created' => $this->rowsCreated,
            'rows_updated' => $this->rowsUpdated,
            'rows_unchanged' => $this->rowsUnchanged,
            'rows_preserved_by_override' => $this->rowsPreservedByOverride,
            'coverage' => $this->coverage,
            'year_status' => $this->yearStatus,
            'revision' => $this->revision,
            'checksum' => $this->checksum,
        ];
    }
}
