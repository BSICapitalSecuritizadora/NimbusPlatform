<?php

namespace App\Domain\PuCalculator\Services;

use App\Models\BusinessCalendarDate;
use App\Models\BusinessCalendarOverride;
use App\Models\BusinessCalendarYear;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class BusinessCalendarOverrideService
{
    public function __construct(
        private readonly BusinessCalendarYearService $yearService,
        private readonly BusinessCalendarRevisionService $revisionService,
        private readonly BusinessDayCalendarService $calendarService,
        private readonly BusinessCalendarCatalogService $catalog,
    ) {}

    public function apply(
        string $calendarCode,
        CarbonImmutable $date,
        bool $isBusinessDay,
        string $reason,
        int $userId,
    ): BusinessCalendarOverride {
        $calendarCode = $this->catalog->findOrFail($calendarCode)->code;
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('O motivo do override manual é obrigatório.');
        }

        if ($userId <= 0) {
            throw new InvalidArgumentException('O usuário responsável pelo override manual é obrigatório.');
        }

        $calendarYear = null;

        $override = Cache::lock($this->yearService->lockKey($calendarCode), 30)
            ->block((int) config('pu_calculator.business_calendar.lock_wait_seconds', 15), function () use (
                $calendarCode,
                $date,
                $isBusinessDay,
                $reason,
                $userId,
                &$calendarYear,
            ): BusinessCalendarOverride {
                return DB::transaction(function () use (
                    $calendarCode,
                    $date,
                    $isBusinessDay,
                    $reason,
                    $userId,
                    &$calendarYear,
                ): BusinessCalendarOverride {
                    $calendarYear = $this->yearService->findOrCreateForUpdate(
                        $calendarCode,
                        $date->year,
                        ['source' => 'manual'],
                    );
                    $calendarDate = BusinessCalendarDate::query()
                        ->where('calendar_code', $calendarCode)
                        ->whereDate('calendar_date', $date->toDateString())
                        ->lockForUpdate()
                        ->first();
                    $previousOverride = BusinessCalendarOverride::query()
                        ->where('calendar_code', $calendarCode)
                        ->whereDate('calendar_date', $date->toDateString())
                        ->latest('applied_at')
                        ->latest('id')
                        ->lockForUpdate()
                        ->first();
                    $previousValue = $previousOverride?->new_is_business_day
                        ?? $calendarDate?->is_business_day
                        ?? ! $date->isWeekend();

                    if ((bool) $previousValue === $isBusinessDay) {
                        throw new InvalidArgumentException('O novo valor do override deve ser diferente da decisão atual.');
                    }

                    $calendarYear->revision = ((int) $calendarYear->revision) + 1;

                    if ($calendarYear->status === BusinessCalendarYear::STATUS_CONFIRMED) {
                        $calendarYear->status = BusinessCalendarYear::STATUS_STALE;
                    }

                    $calendarYear->save();

                    $override = BusinessCalendarOverride::query()->create([
                        'business_calendar_year_id' => $calendarYear->id,
                        'calendar_code' => $calendarCode,
                        'calendar_date' => $date->toDateString(),
                        'reason' => $reason,
                        'previous_is_business_day' => (bool) $previousValue,
                        'new_is_business_day' => $isBusinessDay,
                        'created_by' => $userId,
                        'applied_at' => now(),
                        'revision' => $calendarYear->revision,
                    ]);

                    $calendarDate ??= new BusinessCalendarDate([
                        'calendar_code' => $calendarCode,
                        'calendar_date' => $date->toDateString(),
                    ]);
                    $calendarDate->fill([
                        'business_calendar_year_id' => $calendarYear->id,
                        'is_business_day' => $isBusinessDay,
                        'description' => Str::limit($reason, 255, ''),
                        'data_origin' => 'manual_override',
                        'source' => 'manual',
                        'source_is_official' => false,
                        'source_document' => $calendarYear->source_document,
                        'source_revision' => $calendarYear->source_revision,
                        'revision' => $calendarYear->revision,
                        'import_run_id' => null,
                    ])->save();

                    return $override->load('createdBy:id,name');
                });
            });

        if ($calendarYear instanceof BusinessCalendarYear) {
            $this->revisionService->publish($calendarYear);
        }

        $this->calendarService->flushCache();

        return $override;
    }
}
