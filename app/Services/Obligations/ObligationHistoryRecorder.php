<?php

namespace App\Services\Obligations;

use App\Models\Obligation;
use App\Models\ObligationEvidence;
use App\Models\ObligationHistoryEntry;
use App\Models\User;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Str;

class ObligationHistoryRecorder
{
    /**
     * Fields whose changes are meaningful enough to be recorded in the
     * obligation timeline. Anything else (e.g. updated_at, internal notes)
     * is intentionally ignored to avoid noise.
     *
     * @var list<string>
     */
    public const RELEVANT_FIELDS = [
        'status',
        'due_date',
        'responsible_user_id',
        'title',
        'description',
        'priority',
    ];

    /**
     * Source override applied while running inside a known automated context
     * (e.g. the scheduled status recalculation).
     */
    protected static ?string $sourceOverride = null;

    /**
     * Run a callback while forcing every recorded event to a given source.
     */
    public static function usingSource(string $source, Closure $callback): mixed
    {
        $previous = self::$sourceOverride;
        self::$sourceOverride = $source;

        try {
            return $callback();
        } finally {
            self::$sourceOverride = $previous;
        }
    }

    /**
     * Record the creation of an obligation, distinguishing manual creation
     * from obligations consolidated out of the securitization term.
     */
    public function recordCreated(Obligation $obligation): ObligationHistoryEntry
    {
        $snapshot = $this->snapshot($obligation);

        if ($obligation->extracted_obligation_id !== null) {
            return $this->record(
                $obligation,
                ObligationHistoryEntry::EVENT_GENERATED_FROM_TERM,
                ObligationHistoryEntry::SOURCE_TERM_EXTRACTION,
                'Obrigação gerada a partir do Termo de Securitização',
                'A obrigação foi consolidada automaticamente a partir de uma sugestão extraída do Termo.',
                newValues: $snapshot,
                metadata: array_filter([
                    'extracted_obligation_id' => $obligation->extracted_obligation_id,
                    'confidence_score' => $obligation->extractedObligation?->confidence_score,
                ], fn (mixed $value): bool => $value !== null),
            );
        }

        return $this->record(
            $obligation,
            ObligationHistoryEntry::EVENT_CREATED,
            $this->currentSource(),
            'Obrigação criada',
            'A obrigação foi cadastrada na emissão.',
            newValues: $snapshot,
        );
    }

    /**
     * Record a meaningful update. Returns null when no relevant field changed,
     * keeping the timeline free of irrelevant noise (e.g. updated_at only).
     */
    public function recordUpdated(Obligation $obligation): ?ObligationHistoryEntry
    {
        $changedKeys = array_values(array_intersect(self::RELEVANT_FIELDS, array_keys($obligation->getChanges())));

        if ($changedKeys === []) {
            return null;
        }

        $old = [];
        $new = [];

        foreach ($changedKeys as $field) {
            $old[$field] = $this->normalize($field, $obligation->getOriginal($field));
            $new[$field] = $this->normalize($field, $obligation->getAttribute($field));
        }

        $source = $this->currentSource();
        [$eventType, $title] = $this->resolveUpdateEvent($changedKeys, $new['status'] ?? null, $source);

        return $this->record(
            $obligation,
            $eventType,
            $source,
            $title,
            $this->describeChanges($changedKeys, $old, $new),
            oldValues: $old,
            newValues: $new,
        );
    }

    public function recordNotificationSent(
        Obligation $obligation,
        string $milestone,
        string $notificationType,
        string $recipient,
        ?CarbonInterface $occurredAt = null,
    ): ObligationHistoryEntry {
        return $this->record(
            $obligation,
            ObligationHistoryEntry::EVENT_NOTIFICATION_SENT,
            ObligationHistoryEntry::SOURCE_NOTIFICATION,
            'Notificação de vencimento enviada',
            sprintf('Notificação (%s) enviada para %s.', $this->milestoneLabel($milestone), $recipient),
            metadata: [
                'milestone' => $milestone,
                'notification_type' => $notificationType,
                'recipient' => $recipient,
            ],
            userId: null,
            occurredAt: $occurredAt,
        );
    }

    public function recordNotificationFailed(
        Obligation $obligation,
        string $milestone,
        string $notificationType,
        string $recipient,
        string $errorMessage,
        ?CarbonInterface $occurredAt = null,
    ): ObligationHistoryEntry {
        return $this->record(
            $obligation,
            ObligationHistoryEntry::EVENT_NOTIFICATION_FAILED,
            ObligationHistoryEntry::SOURCE_NOTIFICATION,
            'Falha no envio de notificação',
            sprintf('Não foi possível enviar a notificação (%s). A equipe técnica foi registrada no log do sistema.', $this->milestoneLabel($milestone)),
            metadata: [
                'milestone' => $milestone,
                'notification_type' => $notificationType,
                'recipient' => $recipient,
                'error' => Str::limit($errorMessage, 300),
            ],
            userId: null,
            occurredAt: $occurredAt,
        );
    }

    public function recordEvidenceUploaded(Obligation $obligation, ObligationEvidence $evidence): ObligationHistoryEntry
    {
        $description = sprintf('Evidência "%s" anexada à obrigação.', $evidence->original_name);

        if (filled($evidence->description)) {
            $description .= ' Observação: '.$evidence->description;
        }

        return $this->record(
            $obligation,
            ObligationHistoryEntry::EVENT_EVIDENCE_UPLOADED,
            ObligationHistoryEntry::SOURCE_MANUAL,
            'Evidência anexada à obrigação',
            $description,
            metadata: [
                'evidence_id' => $evidence->id,
                'original_name' => $evidence->original_name,
                'mime_type' => $evidence->mime_type,
                'size' => $evidence->size,
            ],
            userId: $evidence->uploaded_by,
        );
    }

    public function recordEvidenceRemoved(Obligation $obligation, ObligationEvidence $evidence): ObligationHistoryEntry
    {
        return $this->record(
            $obligation,
            ObligationHistoryEntry::EVENT_EVIDENCE_REMOVED,
            ObligationHistoryEntry::SOURCE_MANUAL,
            'Evidência removida da obrigação',
            sprintf('Evidência "%s" removida da obrigação.', $evidence->original_name),
            metadata: [
                'evidence_id' => $evidence->id,
                'original_name' => $evidence->original_name,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     * @param  array<string, mixed>|null  $metadata
     */
    public function recordWorkflowTransition(
        Obligation $obligation,
        string $eventType,
        string $title,
        string $description,
        array $oldValues,
        array $newValues,
        ?array $metadata = null,
        ?int $userId = null,
    ): ObligationHistoryEntry {
        return $this->record(
            $obligation,
            $eventType,
            ObligationHistoryEntry::SOURCE_WORKFLOW,
            $title,
            $description,
            oldValues: $oldValues,
            newValues: $newValues,
            metadata: $metadata,
            userId: $userId,
        );
    }

    /**
     * Low-level, standardized entry point used by all helpers above.
     *
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     * @param  array<string, mixed>|null  $metadata
     */
    public function record(
        Obligation $obligation,
        string $eventType,
        string $source,
        string $title,
        ?string $description = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?array $metadata = null,
        ?int $userId = -1,
        ?CarbonInterface $occurredAt = null,
    ): ObligationHistoryEntry {
        return ObligationHistoryEntry::create([
            'obligation_id' => $obligation->id,
            'emission_id' => $obligation->emission_id,
            'user_id' => $userId === -1 ? $this->resolveUserId($source) : $userId,
            'event_type' => $eventType,
            'source' => $source,
            'title' => $title,
            'description' => $description,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'metadata' => $metadata,
            'occurred_at' => $occurredAt ?? now(),
        ]);
    }

    protected function currentSource(): string
    {
        return self::$sourceOverride
            ?? (auth()->check() ? ObligationHistoryEntry::SOURCE_MANUAL : ObligationHistoryEntry::SOURCE_SYSTEM);
    }

    protected function resolveUserId(string $source): ?int
    {
        return in_array($source, [
            ObligationHistoryEntry::SOURCE_MANUAL,
            ObligationHistoryEntry::SOURCE_WORKFLOW,
        ], true) ? auth()->id() : null;
    }

    /**
     * @param  list<string>  $changedKeys
     * @return array{0: string, 1: string}
     */
    protected function resolveUpdateEvent(array $changedKeys, ?string $newStatus, string $source): array
    {
        if (in_array('status', $changedKeys, true)) {
            return match (true) {
                $source === ObligationHistoryEntry::SOURCE_SCHEDULED_COMMAND => [ObligationHistoryEntry::EVENT_RECALCULATED_STATUS, 'Status recalculado automaticamente'],
                $newStatus === 'concluida' => [ObligationHistoryEntry::EVENT_COMPLETED, 'Obrigação concluída'],
                $newStatus === 'nao_aplicavel' => [ObligationHistoryEntry::EVENT_WAIVED, 'Obrigação marcada como não aplicável'],
                default => [ObligationHistoryEntry::EVENT_STATUS_CHANGED, 'Status da obrigação alterado'],
            };
        }

        if (in_array('due_date', $changedKeys, true)) {
            return [ObligationHistoryEntry::EVENT_DUE_DATE_CHANGED, 'Data de vencimento alterada'];
        }

        if (in_array('responsible_user_id', $changedKeys, true)) {
            return [ObligationHistoryEntry::EVENT_RESPONSIBLE_CHANGED, 'Responsável alterado'];
        }

        return [ObligationHistoryEntry::EVENT_UPDATED, 'Obrigação atualizada'];
    }

    /**
     * Build a human-readable description of every relevant change.
     *
     * @param  list<string>  $changedKeys
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    protected function describeChanges(array $changedKeys, array $old, array $new): string
    {
        $sentences = [];

        foreach ($changedKeys as $field) {
            $sentences[] = match ($field) {
                'status' => sprintf('Status alterado de %s para %s.', Obligation::STATUS_OPTIONS[$old['status']] ?? $old['status'], Obligation::STATUS_OPTIONS[$new['status']] ?? $new['status']),
                'priority' => sprintf('Prioridade alterada de %s para %s.', Obligation::PRIORITY_OPTIONS[$old['priority']] ?? $old['priority'], Obligation::PRIORITY_OPTIONS[$new['priority']] ?? $new['priority']),
                'due_date' => $this->describeDueDateChange($old['due_date'], $new['due_date']),
                'responsible_user_id' => sprintf('Responsável alterado de %s para %s.', $this->userName($old['responsible_user_id']), $this->userName($new['responsible_user_id'])),
                'title' => 'Título atualizado.',
                'description' => 'Descrição atualizada.',
                default => ucfirst($field).' atualizado.',
            };
        }

        return implode(' ', $sentences);
    }

    protected function describeDueDateChange(?string $old, ?string $new): string
    {
        $oldLabel = $old !== null ? $this->formatDate($old) : null;
        $newLabel = $new !== null ? $this->formatDate($new) : null;

        return match (true) {
            $oldLabel === null && $newLabel !== null => sprintf('Data de vencimento definida para %s.', $newLabel),
            $oldLabel !== null && $newLabel === null => 'Data de vencimento removida.',
            default => sprintf('Data de vencimento alterada de %s para %s.', $oldLabel, $newLabel),
        };
    }

    /**
     * Normalize a value for stable JSON storage (dates as Y-m-d strings).
     */
    protected function normalize(string $field, mixed $value): mixed
    {
        if ($value instanceof CarbonInterface) {
            return $value->format('Y-m-d');
        }

        return $value;
    }

    protected function formatDate(string $value): string
    {
        return \Illuminate\Support\Carbon::parse($value)->format('d/m/Y');
    }

    protected function userName(mixed $userId): string
    {
        if ($userId === null) {
            return '—';
        }

        return User::query()->whereKey($userId)->value('name') ?? '—';
    }

    /**
     * @return array<string, mixed>
     */
    protected function snapshot(Obligation $obligation): array
    {
        return [
            'title' => $obligation->title,
            'status' => $obligation->status,
            'priority' => $obligation->priority,
            'due_date' => $this->normalize('due_date', $obligation->due_date),
            'responsible_user_id' => $obligation->responsible_user_id,
        ];
    }

    protected function milestoneLabel(string $milestone): string
    {
        return match (true) {
            $milestone === 'due_today' => 'vence hoje',
            $milestone === 'overdue' => 'vencida',
            str_starts_with($milestone, 'due_') => str_replace('due_', '', $milestone).' dias',
            default => $milestone,
        };
    }
}
