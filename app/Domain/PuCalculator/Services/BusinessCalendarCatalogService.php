<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Support\BusinessCalendarRegistry;
use App\Models\BusinessCalendar;
use App\Models\BusinessCalendarYear;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

final class BusinessCalendarCatalogService
{
    /** @return Collection<int, BusinessCalendar> */
    public function calendars(bool $includeHomologation = true): Collection
    {
        return BusinessCalendar::query()
            ->when(! $includeHomologation, fn ($query) => $query->where('is_homologation', false))
            ->withCount([
                'years as confirmed_years_count' => fn ($query) => $query->where('status', BusinessCalendarYear::STATUS_CONFIRMED),
            ])
            ->orderBy('is_legacy')
            ->orderBy('code')
            ->get();
    }

    /** @return array<string, array<string, mixed>> */
    public function definitions(bool $includeHomologation = true): array
    {
        return $this->calendars($includeHomologation)
            ->mapWithKeys(fn (BusinessCalendar $calendar): array => [
                $calendar->code => [
                    'label' => $calendar->selectionLabel(),
                    'meaning' => $calendar->purpose,
                    'type' => $calendar->calendar_type,
                    'source' => $calendar->source,
                    'status' => $calendar->status,
                    'import_mode' => $calendar->import_mode,
                    'materialization_policy' => $calendar->materialization_policy,
                    'coverage_basis' => $calendar->coverageBasis(),
                    'official' => $calendar->is_official,
                    'financial_use_allowed' => $calendar->financial_use_allowed,
                    'legacy' => $calendar->is_legacy,
                    'homologation' => $calendar->is_homologation,
                    'accepts_anbima' => $calendar->accepts_anbima,
                    'available_for_new_configurations' => $calendar->available_for_new_configurations,
                    'confirmed_years_count' => (int) $calendar->confirmed_years_count,
                ],
            ])
            ->all();
    }

    /** @return array<string, string> */
    public function administrativeOptions(bool $includeHomologation = true): array
    {
        return $this->calendars($includeHomologation)
            ->mapWithKeys(fn (BusinessCalendar $calendar): array => [$calendar->code => $calendar->selectionLabel()])
            ->all();
    }

    /** @return array<string, string> */
    public function anbimaImportOptions(): array
    {
        return BusinessCalendar::query()
            ->where('code', BusinessCalendarRegistry::BR_BANKING_ANBIMA)
            ->where('accepts_anbima', true)
            ->get()
            ->mapWithKeys(fn (BusinessCalendar $calendar): array => [$calendar->code => $calendar->selectionLabel()])
            ->all();
    }

    /**
     * Opções normais nunca incluem HML, códigos legados ou calendários ainda sem fonte aprovada.
     * O código atual é acrescentado somente para permitir leitura/edição retrocompatível.
     *
     * @return array<string, string>
     */
    public function optionsForNewConfiguration(?string $currentCode = null): array
    {
        $options = BusinessCalendar::query()
            ->where('available_for_new_configurations', true)
            ->where('financial_use_allowed', true)
            ->where('is_homologation', false)
            ->where('is_legacy', false)
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (BusinessCalendar $calendar): array => [$calendar->code => $calendar->selectionLabel()]);

        if (filled($currentCode)) {
            $normalized = BusinessCalendarRegistry::normalize((string) $currentCode);
            $current = BusinessCalendar::query()->where('code', $normalized)->first();
            $options->put($normalized, $current?->selectionLabel() ?? sprintf('%s — Configuração existente não catalogada', $normalized));
        }

        return $options->all();
    }

    public function findOrFail(string $calendarCode): BusinessCalendar
    {
        $normalized = BusinessCalendarRegistry::normalize($calendarCode);
        $calendar = BusinessCalendar::query()->where('code', $normalized)->first();

        if (! $calendar instanceof BusinessCalendar) {
            throw new InvalidArgumentException(sprintf('Calendário [%s] não existe no catálogo.', $calendarCode));
        }

        return $calendar;
    }
}
