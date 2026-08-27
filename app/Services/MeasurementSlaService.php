<?php

namespace App\Services;

use App\Domain\PuCalculator\Contracts\BusinessDayCalendar;
use App\Models\BusinessCalendar;
use App\Models\BusinessCalendarYear;
use App\Models\Measurement;
use App\Models\MeasurementPause;
use App\Models\MeasurementReview;
use App\Models\SlaConfiguration;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class MeasurementSlaService
{
    /** @var array<int, array<string, mixed>> */
    private array $resolvedConfigurations = [];

    /** @var array<string, true> */
    private array $verifiedCalendars = [];

    /** @var array<string, array<int, true>> */
    private array $verifiedCalendarYears = [];

    public const STATUS_ON_TIME = 'on_time';

    public const STATUS_APPROACHING = 'approaching';

    public const STATUS_OVERDUE = 'overdue';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_NOT_CONFIGURED = 'not_configured';

    public const STATUS_NOT_APPLICABLE = 'not_applicable';

    public const STATUS_INVALID_CONFIG = 'invalid_config';

    public const STATUS_CALENDAR_UNAVAILABLE = 'calendar_unavailable';

    public function __construct(private BusinessDayCalendar $calendar) {}

    /**
     * A reabertura preserva o created_at do registro da etapa e, portanto, continua o ciclo
     * operacional original. Delegações nunca participam do cálculo nem reiniciam o relógio.
     *
     * @return array<string, mixed>
     */
    public function evaluate(Measurement $measurement, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $stage = app(MeasurementWorkflow::class)->unifiedStage($measurement);

        if ($stage === 0 || in_array($measurement->status, ['finalized', 'rejected'], true)) {
            return $this->result($stage, status: self::STATUS_COMPLETED, completed: true);
        }

        $review = $this->reviewForStage($measurement, $stage);

        if (! $review instanceof MeasurementReview || $review->status !== 'pending') {
            return $this->result($stage, status: self::STATUS_NOT_APPLICABLE, completed: true);
        }

        $startedAt = $this->stageStartedAt($measurement, $stage);
        $config = $this->resolveConfig($stage);

        if ($startedAt === null || $config['not_configured']) {
            return $this->result(
                $stage,
                startedAt: $startedAt,
                status: self::STATUS_NOT_CONFIGURED,
                notConfigured: true,
                config: $config,
            );
        }

        if ($config['invalid']) {
            Log::warning('SLA invalid configuration', ['stage' => $stage, 'config' => $config]);

            return $this->result(
                $stage,
                startedAt: $startedAt,
                status: self::STATUS_INVALID_CONFIG,
                invalid: true,
                config: $config,
            );
        }

        $calendarUnavailableReason = $this->calendarUnavailableReason(
            $startedAt,
            $now,
            $config['calendar_code'],
        );

        if ($calendarUnavailableReason !== null) {
            Log::warning('SLA calendar unavailable', [
                'stage' => $stage,
                'calendar_code' => $config['calendar_code'],
                'reason' => $calendarUnavailableReason,
            ]);

            return $this->result(
                $stage,
                startedAt: $startedAt,
                status: self::STATUS_CALENDAR_UNAVAILABLE,
                calendarUnavailable: true,
                config: $config,
            );
        }

        $durationSeconds = $this->durationSeconds($config);
        $totalBusinessSeconds = $this->businessSecondsBetween($startedAt, $now, $config['calendar_code']);
        $pausedBusinessSeconds = $config['exclude_paused_time']
            ? $this->pausedBusinessSeconds($measurement, $stage, $startedAt, $now, $config['calendar_code'])
            : 0;
        $elapsedSeconds = max(0, $totalBusinessSeconds - $pausedBusinessSeconds);
        $deadlineEvaluation = $this->addBusinessSeconds(
            $startedAt,
            $durationSeconds + $pausedBusinessSeconds,
            $config['calendar_code'],
        );

        if ($deadlineEvaluation['unavailable_reason'] !== null) {
            Log::warning('SLA calendar unavailable for projected deadline', [
                'stage' => $stage,
                'calendar_code' => $config['calendar_code'],
                'reason' => $deadlineEvaluation['unavailable_reason'],
            ]);

            return $this->result(
                $stage,
                startedAt: $startedAt,
                elapsedSeconds: $elapsedSeconds,
                durationSeconds: $durationSeconds,
                status: self::STATUS_CALENDAR_UNAVAILABLE,
                calendarUnavailable: true,
                config: $config,
            );
        }

        $deadlineAt = $deadlineEvaluation['deadline_at'];
        $paused = $measurement->status === 'paused';
        $status = $paused
            ? self::STATUS_PAUSED
            : $this->statusFrom($elapsedSeconds, $durationSeconds, $config);

        return $this->result(
            $stage,
            startedAt: $startedAt,
            deadlineAt: $deadlineAt,
            elapsedSeconds: $elapsedSeconds,
            durationSeconds: $durationSeconds,
            status: $status,
            paused: $paused,
            config: $config,
        );
    }

    public function stageStartedAt(Measurement $measurement, int $stage): ?CarbonImmutable
    {
        $review = $this->reviewForStage($measurement, $stage);
        $candidate = $review?->created_at ?? $measurement->created_at;

        return $candidate ? CarbonImmutable::instance($candidate) : null;
    }

    /** @return array<string, mixed> */
    public function resolveConfig(int $stage): array
    {
        if (isset($this->resolvedConfigurations[$stage])) {
            return $this->resolvedConfigurations[$stage];
        }

        $dbConfig = SlaConfiguration::activeForStage($stage);

        if ($dbConfig instanceof SlaConfiguration) {
            return $this->resolvedConfigurations[$stage] = $this->normalizedConfig(
                $stage,
                (int) $dbConfig->duration_value,
                (string) $dbConfig->duration_unit,
                (int) $dbConfig->warning_threshold_percent,
                (int) $dbConfig->escalation_threshold_percent,
                (bool) $dbConfig->exclude_paused_time,
            );
        }

        $duration = Config::get("measurements.sla.stage_deadlines.{$stage}");

        if ($duration === null) {
            return $this->resolvedConfigurations[$stage] = [
                'stage' => $stage,
                'duration_value' => null,
                'duration_unit' => 'days',
                'warning_threshold_percent' => null,
                'escalation_threshold_percent' => 100,
                'calendar_code' => $this->calendarCode(),
                'exclude_paused_time' => true,
                'not_configured' => true,
                'invalid' => false,
                'reason' => 'Nenhum prazo foi configurado para a etapa.',
            ];
        }

        return $this->resolvedConfigurations[$stage] = $this->normalizedConfig(
            $stage,
            (int) $duration,
            'days',
            (int) Config::get('measurements.sla.warning_threshold_percent', 75),
            (int) Config::get('measurements.sla.escalation_threshold_percent', 100),
            true,
        );
    }

    /** @return array<string, mixed> */
    private function normalizedConfig(
        int $stage,
        int $duration,
        string $unit,
        int $warning,
        int $escalation,
        bool $excludePausedTime,
    ): array {
        $reason = match (true) {
            $stage < 1 || $stage > 5 => 'Etapa deve estar entre 1 e 5.',
            $duration <= 0 => 'Duração deve ser maior que zero.',
            ! in_array($unit, ['days', 'hours'], true) => 'Unidade deve ser days ou hours.',
            $warning <= 0 || $warning >= 100 => 'Percentual de atenção deve estar entre 1 e 99.',
            $escalation <= 0 || $escalation > 100 => 'Percentual de vencimento deve estar entre 1 e 100.',
            $warning >= $escalation => 'Percentual de atenção deve ser menor que o de vencimento.',
            default => null,
        };

        return [
            'stage' => $stage,
            'duration_value' => $duration,
            'duration_unit' => $unit,
            'warning_threshold_percent' => $warning,
            'escalation_threshold_percent' => $escalation,
            'calendar_code' => $this->calendarCode(),
            'exclude_paused_time' => $excludePausedTime,
            'not_configured' => false,
            'invalid' => $reason !== null,
            'reason' => $reason,
        ];
    }

    public function calendarCode(): ?string
    {
        $code = Config::get('measurements.sla.calendar_code');

        return filled($code) ? (string) $code : null;
    }

    private function reviewForStage(Measurement $measurement, int $stage): ?MeasurementReview
    {
        if ($measurement->relationLoaded('reviews')) {
            return $measurement->reviews->first(
                fn (MeasurementReview $review): bool => (int) $review->stage === $stage,
            );
        }

        return $measurement->reviews()->where('stage', $stage)->first();
    }

    private function durationSeconds(array $config): int
    {
        $hours = $config['duration_unit'] === 'days'
            ? (int) $config['duration_value'] * 24
            : (int) $config['duration_value'];

        return $hours * 3600;
    }

    private function statusFrom(int $elapsedSeconds, int $durationSeconds, array $config): string
    {
        $elapsedPercent = $durationSeconds > 0 ? ($elapsedSeconds / $durationSeconds) * 100 : 100;

        if ($elapsedPercent >= (int) $config['escalation_threshold_percent']) {
            return self::STATUS_OVERDUE;
        }

        if ($elapsedPercent >= (int) $config['warning_threshold_percent']) {
            return self::STATUS_APPROACHING;
        }

        return self::STATUS_ON_TIME;
    }

    private function pausedBusinessSeconds(
        Measurement $measurement,
        int $stage,
        CarbonImmutable $startedAt,
        CarbonImmutable $now,
        ?string $calendarCode,
    ): int {
        $pauses = $measurement->relationLoaded('pauses')
            ? $measurement->pauses
            : $measurement->pauses()->where('stage', $stage)->get();

        return $pauses
            ->filter(fn (MeasurementPause $pause): bool => (int) $pause->stage === $stage && $pause->paused_at !== null)
            ->sum(function (MeasurementPause $pause) use ($startedAt, $now, $calendarCode): int {
                $pauseStart = CarbonImmutable::instance($pause->paused_at)->max($startedAt);
                $pauseEnd = $pause->resumed_at
                    ? CarbonImmutable::instance($pause->resumed_at)->min($now)
                    : $now;

                return $pauseEnd->greaterThan($pauseStart)
                    ? $this->businessSecondsBetween($pauseStart, $pauseEnd, $calendarCode)
                    : 0;
            });
    }

    private function businessSecondsBetween(
        CarbonImmutable $start,
        CarbonImmutable $end,
        ?string $calendarCode,
    ): int {
        if ($end->lessThanOrEqualTo($start)) {
            return 0;
        }

        $seconds = 0;
        $cursor = $start->startOfDay();

        while ($cursor->lessThanOrEqualTo($end->startOfDay())) {
            if ($this->isBusinessDay($cursor, $calendarCode)) {
                $intervalStart = $start->greaterThan($cursor) ? $start : $cursor;
                $dayEnd = $cursor->endOfDay()->addSecond();
                $intervalEnd = $end->lessThan($dayEnd) ? $end : $dayEnd;

                if ($intervalEnd->greaterThan($intervalStart)) {
                    $seconds += (int) $intervalStart->diffInSeconds($intervalEnd, true);
                }
            }

            $cursor = $cursor->addDay();
        }

        return $seconds;
    }

    /** @return array{deadline_at: ?CarbonImmutable, unavailable_reason: ?string} */
    private function addBusinessSeconds(
        CarbonImmutable $start,
        int $seconds,
        ?string $calendarCode,
    ): array {
        $cursor = $start;
        $remaining = $seconds;

        while ($remaining > 0) {
            $calendarUnavailableReason = $this->calendarUnavailableReason(
                $cursor,
                $cursor,
                $calendarCode,
            );

            if ($calendarUnavailableReason !== null) {
                return [
                    'deadline_at' => null,
                    'unavailable_reason' => $calendarUnavailableReason,
                ];
            }

            if (! $this->isBusinessDay($cursor, $calendarCode)) {
                $cursor = $cursor->addDay()->startOfDay();

                continue;
            }

            $dayEnd = $cursor->endOfDay()->addSecond();
            $available = (int) $cursor->diffInSeconds($dayEnd, true);

            if ($remaining <= $available) {
                $deadlineAt = $cursor->addSeconds($remaining);
                $deadlineUnavailableReason = $this->calendarUnavailableReason(
                    $deadlineAt,
                    $deadlineAt,
                    $calendarCode,
                );

                if ($deadlineUnavailableReason !== null) {
                    return [
                        'deadline_at' => null,
                        'unavailable_reason' => $deadlineUnavailableReason,
                    ];
                }

                return [
                    'deadline_at' => $deadlineAt,
                    'unavailable_reason' => null,
                ];
            }

            $remaining -= $available;
            $cursor = $cursor->addDay()->startOfDay();
        }

        return [
            'deadline_at' => $cursor,
            'unavailable_reason' => null,
        ];
    }

    private function isBusinessDay(CarbonImmutable $date, ?string $calendarCode): bool
    {
        return $calendarCode === null
            ? ! $date->isWeekend()
            : $this->calendar->isBusinessDay($date, $calendarCode);
    }

    private function calendarUnavailableReason(
        CarbonImmutable $start,
        CarbonImmutable $end,
        ?string $calendarCode,
    ): ?string {
        if ($calendarCode === null) {
            return null;
        }

        if (! isset($this->verifiedCalendars[$calendarCode])) {
            if (! BusinessCalendar::query()->where('code', $calendarCode)->exists()) {
                return "Calendário {$calendarCode} não cadastrado.";
            }

            $this->verifiedCalendars[$calendarCode] = true;
        }

        foreach (range($start->year, $end->year) as $year) {
            if (! isset($this->verifiedCalendarYears[$calendarCode][$year])) {
                if (! BusinessCalendarYear::query()
                    ->where('calendar_code', $calendarCode)
                    ->where('year', $year)
                    ->exists()) {
                    return "Calendário {$calendarCode} não materializado para {$year}.";
                }

                $this->verifiedCalendarYears[$calendarCode][$year] = true;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function result(
        int $stage,
        ?CarbonImmutable $startedAt = null,
        ?CarbonImmutable $deadlineAt = null,
        int $elapsedSeconds = 0,
        int $durationSeconds = 0,
        string $status = self::STATUS_NOT_APPLICABLE,
        bool $paused = false,
        bool $completed = false,
        bool $notConfigured = false,
        bool $invalid = false,
        bool $calendarUnavailable = false,
        ?array $config = null,
    ): array {
        return [
            'stage' => $stage,
            'started_at' => $startedAt,
            'deadline_at' => $deadlineAt,
            'elapsed_business_seconds' => $elapsedSeconds,
            'elapsed_business_hours' => round($elapsedSeconds / 3600, 2),
            'elapsed_business_days' => (int) floor($elapsedSeconds / 86400),
            'remaining_business_seconds' => max(0, $durationSeconds - $elapsedSeconds),
            'remaining_business_days' => $durationSeconds > 0
                ? (int) ceil(max(0, $durationSeconds - $elapsedSeconds) / 86400)
                : null,
            'elapsed_percent' => $durationSeconds > 0
                ? round(($elapsedSeconds / $durationSeconds) * 100, 2)
                : null,
            'status' => $status,
            'paused' => $paused,
            'completed' => $completed,
            'not_configured' => $notConfigured,
            'invalid_config' => $invalid,
            'calendar_unavailable' => $calendarUnavailable,
            'config' => $config,
        ];
    }
}
