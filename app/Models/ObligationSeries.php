<?php

namespace App\Models;

use App\Enums\ObligationDueRuleType;
use App\Enums\ObligationFrequency;
use App\Enums\ObligationInvalidDayPolicy;
use App\Enums\ObligationSeriesStatus;
use Database\Factories\ObligationSeriesFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ObligationSeries extends Model
{
    /** @use HasFactory<ObligationSeriesFactory> */
    use HasFactory, LogsActivity;

    protected $fillable = [
        'emission_id',
        'extracted_obligation_id',
        'document_id',
        'responsible_user_id',
        'title',
        'obligation_type',
        'obligation_category',
        'description',
        'responsible_party',
        'responsible_area',
        'priority',
        'required_evidence',
        'due_rule',
        'source_clause',
        'source_page',
        'source_excerpt',
        'frequency',
        'starts_on',
        'ends_on',
        'due_rule_type',
        'due_day',
        'due_offset_months',
        'due_offset_days',
        'invalid_day_policy',
        'calendar_code',
        'generation_horizon_days',
        'status',
        'is_legacy_backfill',
        'configuration_confirmed_at',
        'configuration_confirmed_by',
        'paused_at',
        'paused_by',
        'pause_reason',
        'closed_at',
        'closed_by',
        'close_reason',
    ];

    protected $attributes = [
        'priority' => 'medium',
        'generation_horizon_days' => 90,
        'status' => ObligationSeriesStatus::AwaitingConfiguration->value,
        'is_legacy_backfill' => false,
        'due_offset_months' => 0,
    ];

    protected function casts(): array
    {
        return [
            'frequency' => ObligationFrequency::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
            'due_rule_type' => ObligationDueRuleType::class,
            'due_day' => 'integer',
            'due_offset_months' => 'integer',
            'due_offset_days' => 'integer',
            'invalid_day_policy' => ObligationInvalidDayPolicy::class,
            'generation_horizon_days' => 'integer',
            'status' => ObligationSeriesStatus::class,
            'is_legacy_backfill' => 'boolean',
            'source_page' => 'integer',
            'configuration_confirmed_at' => 'datetime',
            'paused_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('obligation_series')
            ->logOnly([
                'title', 'responsible_user_id', 'responsible_area', 'priority',
                'frequency', 'starts_on', 'ends_on', 'due_rule_type', 'due_day',
                'due_offset_months', 'due_offset_days', 'invalid_day_policy', 'calendar_code', 'status',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function getFrequencyLabelAttribute(): string
    {
        return $this->frequency?->label() ?? 'A definir';
    }

    public function getStatusLabelAttribute(): string
    {
        return $this->status->label();
    }

    public function getRuleSummaryAttribute(): string
    {
        if ($this->frequency === ObligationFrequency::OnDemand) {
            return 'Ocorrências geradas manualmente sob demanda.';
        }

        $monthOffset = match ($this->due_offset_months) {
            0 => 'da competência',
            1 => 'do mês seguinte',
            -1 => 'do mês anterior',
            default => sprintf('%+d mês(es) em relação à competência', $this->due_offset_months),
        };

        return match ($this->due_rule_type) {
            ObligationDueRuleType::FixedDay => sprintf('Dia %d %s', $this->due_day, $monthOffset),
            ObligationDueRuleType::LastDay => sprintf('Último dia %s', $monthOffset),
            ObligationDueRuleType::NthBusinessDay => sprintf('%dº dia útil %s (%s)', $this->due_day, $monthOffset, $this->calendar_code ?? 'B3'),
            ObligationDueRuleType::CalendarDaysAfterCompetenceEnd => sprintf(
                '%d %s após o encerramento da competência',
                $this->due_offset_days,
                $this->due_offset_days === 1 ? 'dia corrido' : 'dias corridos',
            ),
            default => 'Regra executável ainda não configurada.',
        };
    }

    public function emission(): BelongsTo
    {
        return $this->belongsTo(Emission::class);
    }

    public function extractedObligation(): BelongsTo
    {
        return $this->belongsTo(ExtractedObligation::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function configurationConfirmedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'configuration_confirmed_by');
    }

    public function pausedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paused_by');
    }

    public function closedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function rules(): HasMany
    {
        return $this->hasMany(ObligationSeriesRule::class)->orderBy('effective_from');
    }

    public function latestRule(): HasOne
    {
        return $this->hasOne(ObligationSeriesRule::class)->ofMany('effective_from', 'max');
    }

    public function occurrences(): HasMany
    {
        return $this->hasMany(Obligation::class)->orderBy('competence_date');
    }
}
