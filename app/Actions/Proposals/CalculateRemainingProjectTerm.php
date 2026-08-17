<?php

namespace App\Actions\Proposals;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Throwable;

class CalculateRemainingProjectTerm
{
    public function handle(
        DateTimeInterface|string|null $constructionStart,
        DateTimeInterface|string|null $deliveryForecast,
        DateTimeInterface|string|null $referenceDate = null,
    ): ?int {
        $constructionStart = $this->parseMonth($constructionStart);
        $deliveryForecast = $this->parseMonth($deliveryForecast);
        $referenceDate = $this->parseMonth($referenceDate ?? now());

        if (! $constructionStart || ! $deliveryForecast || ! $referenceDate) {
            return null;
        }

        if ($deliveryForecast->lessThan($constructionStart)) {
            return null;
        }

        $baseDate = $constructionStart->greaterThan($referenceDate)
            ? $constructionStart
            : $referenceDate;

        if ($deliveryForecast->lessThanOrEqualTo($baseDate)) {
            return 0;
        }

        return (int) $baseDate->diffInMonths($deliveryForecast);
    }

    private function parseMonth(DateTimeInterface|string|null $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            if ($value instanceof DateTimeInterface) {
                return CarbonImmutable::instance($value)->startOfMonth();
            }

            $monthOnly = preg_match('/^\d{4}-\d{2}$/', $value) === 1;
            $format = $monthOnly ? '!Y-m' : '!Y-m-d';
            $date = CarbonImmutable::createFromFormat($format, $value);

            if ($date === false || $date->format($monthOnly ? 'Y-m' : 'Y-m-d') !== $value) {
                return null;
            }

            return $date->startOfMonth();
        } catch (Throwable) {
            return null;
        }
    }
}
