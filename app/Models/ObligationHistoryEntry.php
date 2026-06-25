<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ObligationHistoryEntry extends Model
{
    /** @use HasFactory<\Database\Factories\ObligationHistoryEntryFactory> */
    use HasFactory;

    public const EVENT_CREATED = 'created';

    public const EVENT_GENERATED_FROM_TERM = 'generated_from_term';

    public const EVENT_UPDATED = 'updated';

    public const EVENT_STATUS_CHANGED = 'status_changed';

    public const EVENT_DUE_DATE_CHANGED = 'due_date_changed';

    public const EVENT_RESPONSIBLE_CHANGED = 'responsible_changed';

    public const EVENT_RECALCULATED_STATUS = 'recalculated_status';

    public const EVENT_COMPLETED = 'completed';

    public const EVENT_WAIVED = 'waived';

    public const EVENT_SUBMITTED_FOR_REVIEW = 'submitted_for_review';

    public const EVENT_MARKED_NOT_APPLICABLE = 'marked_not_applicable';

    public const EVENT_REOPENED = 'reopened';

    public const EVENT_NOTIFICATION_SENT = 'notification_sent';

    public const EVENT_NOTIFICATION_FAILED = 'notification_failed';

    public const EVENT_EVIDENCE_UPLOADED = 'evidence_uploaded';

    public const EVENT_EVIDENCE_REMOVED = 'evidence_removed';

    public const EVENT_EVIDENCE_APPROVED = 'evidence_approved';

    public const EVENT_EVIDENCE_REJECTED = 'evidence_rejected';

    public const EVENT_EVIDENCE_REVIEW_UPDATED = 'evidence_review_updated';

    public const EVENT_COMMENT_ADDED = 'comment_added';

    public const EVENT_COMMENT_UPDATED = 'comment_updated';

    public const EVENT_COMMENT_REMOVED = 'comment_removed';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_TERM_EXTRACTION = 'term_extraction';

    public const SOURCE_SCHEDULED_COMMAND = 'scheduled_command';

    public const SOURCE_SYSTEM = 'system';

    public const SOURCE_NOTIFICATION = 'notification';

    public const SOURCE_WORKFLOW = 'workflow';

    public const SOURCE_EVIDENCE_REVIEW = 'evidence_review';

    public const SOURCE_COMMENT = 'comment';

    /**
     * Friendly, business-facing labels for each event type.
     *
     * @var array<string, string>
     */
    public const EVENT_LABELS = [
        self::EVENT_CREATED => 'Obrigação criada',
        self::EVENT_GENERATED_FROM_TERM => 'Gerada pelo Termo',
        self::EVENT_UPDATED => 'Obrigação atualizada',
        self::EVENT_STATUS_CHANGED => 'Status alterado',
        self::EVENT_DUE_DATE_CHANGED => 'Vencimento alterado',
        self::EVENT_RESPONSIBLE_CHANGED => 'Responsável alterado',
        self::EVENT_RECALCULATED_STATUS => 'Status recalculado',
        self::EVENT_COMPLETED => 'Obrigação concluída',
        self::EVENT_WAIVED => 'Marcada como não aplicável',
        self::EVENT_SUBMITTED_FOR_REVIEW => 'Enviada para análise',
        self::EVENT_MARKED_NOT_APPLICABLE => 'Marcada como não aplicável',
        self::EVENT_REOPENED => 'Obrigação reaberta',
        self::EVENT_NOTIFICATION_SENT => 'Notificação enviada',
        self::EVENT_NOTIFICATION_FAILED => 'Falha na notificação',
        self::EVENT_EVIDENCE_UPLOADED => 'Evidência anexada',
        self::EVENT_EVIDENCE_REMOVED => 'Evidência removida',
        self::EVENT_EVIDENCE_APPROVED => 'Evidência aprovada',
        self::EVENT_EVIDENCE_REJECTED => 'Evidência rejeitada',
        self::EVENT_EVIDENCE_REVIEW_UPDATED => 'Revisão da evidência atualizada',
        self::EVENT_COMMENT_ADDED => 'Comentário interno adicionado',
        self::EVENT_COMMENT_UPDATED => 'Comentário interno editado',
        self::EVENT_COMMENT_REMOVED => 'Comentário interno removido',
    ];

    /**
     * Friendly labels for the origin of an event, avoiding technical jargon.
     *
     * @var array<string, string>
     */
    public const SOURCE_LABELS = [
        self::SOURCE_MANUAL => 'Manual',
        self::SOURCE_TERM_EXTRACTION => 'Geração pelo Termo',
        self::SOURCE_SCHEDULED_COMMAND => 'Rotina automática',
        self::SOURCE_SYSTEM => 'Sistema',
        self::SOURCE_NOTIFICATION => 'Notificação automática',
        self::SOURCE_WORKFLOW => 'Workflow operacional',
        self::SOURCE_EVIDENCE_REVIEW => 'Revisão de evidência',
        self::SOURCE_COMMENT => 'Comentário interno',
    ];

    protected $fillable = [
        'obligation_id',
        'emission_id',
        'user_id',
        'event_type',
        'source',
        'title',
        'description',
        'old_values',
        'new_values',
        'metadata',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function getEventLabelAttribute(): string
    {
        return self::EVENT_LABELS[$this->event_type] ?? $this->event_type;
    }

    public function getSourceLabelAttribute(): string
    {
        return self::SOURCE_LABELS[$this->source] ?? $this->source;
    }

    /**
     * Human-friendly actor for the timeline: the user name when available,
     * otherwise the friendly origin label (e.g. "Rotina automática").
     */
    public function getActorLabelAttribute(): string
    {
        return $this->user?->name ?? $this->source_label;
    }

    public function obligation(): BelongsTo
    {
        return $this->belongsTo(Obligation::class);
    }

    public function emission(): BelongsTo
    {
        return $this->belongsTo(Emission::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
