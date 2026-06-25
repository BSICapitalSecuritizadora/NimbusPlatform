<?php

namespace App\Models;

use App\Observers\ObligationObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[ObservedBy(ObligationObserver::class)]
class Obligation extends Model
{
    /** @use HasFactory<\Database\Factories\ObligationFactory> */
    use HasFactory, LogsActivity;

    public const STATUS_OPTIONS = [
        'em_dia' => 'Em dia',
        'a_vencer' => 'A vencer',
        'vencida' => 'Vencida',
        'concluida' => 'Concluída',
        'em_analise' => 'Em análise',
        'nao_aplicavel' => 'Não aplicável',
    ];

    public const PRIORITY_OPTIONS = [
        'low' => 'Baixa',
        'medium' => 'Média',
        'high' => 'Alta',
        'critical' => 'Crítica',
    ];

    protected $fillable = [
        'emission_id',
        'extracted_obligation_id',
        'responsible_user_id',
        'title',
        'obligation_type',
        'obligation_category',
        'description',
        'responsible_party',
        'responsible_area',
        'recurrence',
        'due_rule',
        'due_date',
        'priority',
        'status',
        'required_evidence',
        'source_clause',
        'source_page',
        'source_excerpt',
        'notes',
        'completed_at',
        'completed_by',
        'completion_notes',
        'submitted_for_review_at',
        'submitted_for_review_by',
        'review_submission_notes',
        'not_applicable_at',
        'not_applicable_by',
        'not_applicable_reason',
        'reopened_at',
        'reopened_by',
        'reopen_reason',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'source_page' => 'integer',
            'completed_at' => 'datetime',
            'submitted_for_review_at' => 'datetime',
            'not_applicable_at' => 'datetime',
            'reopened_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'title', 'obligation_type', 'obligation_category', 'responsible_party',
                'responsible_area', 'responsible_user_id', 'recurrence', 'due_rule',
                'due_date', 'priority', 'status', 'required_evidence', 'notes',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_OPTIONS[$this->status] ?? $this->status;
    }

    public function getPriorityLabelAttribute(): string
    {
        return self::PRIORITY_OPTIONS[$this->priority] ?? $this->priority;
    }

    public function emission(): BelongsTo
    {
        return $this->belongsTo(Emission::class);
    }

    public function extractedObligation(): BelongsTo
    {
        return $this->belongsTo(ExtractedObligation::class);
    }

    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function completedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(ObligationNotification::class);
    }

    public function evidences(): HasMany
    {
        return $this->hasMany(ObligationEvidence::class);
    }

    public function historyEntries(): HasMany
    {
        return $this->hasMany(ObligationHistoryEntry::class);
    }
}
