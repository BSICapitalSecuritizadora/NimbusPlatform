<?php

namespace App\Services\LegalInstruments;

use App\Enums\AccessPermission;
use App\Enums\LegalInstrumentEventType;
use App\Enums\LegalInstrumentFieldStatus;
use App\Models\LegalInstrument;
use App\Models\LegalInstrumentDocument;
use App\Models\LegalInstrumentEvent;
use App\Models\LegalInstrumentField;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Revisão humana das alterações detectadas (§20 e §21 do escopo).
 *
 * Confirmar uma proposta é o único caminho para a posição vigente mudar. A
 * operação é deliberadamente estreita: promove a linha pendente a `confirmed`,
 * rebaixa a anterior a `superseded` e registra o evento jurídico. Nada é
 * apagado, e por isso o histórico e a consulta retroativa continuam válidos.
 */
class InstrumentChangeReviewService
{
    public const LOG_NAME = 'legal_instrument_changes';

    public const EVENT_CHANGE_CONFIRMED = 'instrument_change_confirmed';

    public const EVENT_CHANGE_REJECTED = 'instrument_change_rejected';

    public function __construct(
        private readonly InstrumentPositionResolver $positionResolver,
    ) {}

    public function canConfirm(?User $user): bool
    {
        return $this->canReview($user)
            && ($user?->can(AccessPermission::LegalInstrumentsConfirmChange->value) ?? false);
    }

    public function canReject(?User $user): bool
    {
        return $this->canReview($user)
            && ($user?->can(AccessPermission::LegalInstrumentsRejectChange->value) ?? false);
    }

    private function canReview(?User $user): bool
    {
        return $user?->can(AccessPermission::LegalInstrumentsReviewChanges->value) ?? false;
    }

    /**
     * Confirma uma proposta de alteração.
     *
     * O valor anterior é resolvido *antes* da promoção, para que o evento
     * registre o "de → para" real e não o estado já alterado.
     */
    public function confirm(LegalInstrumentField $proposal, User $actor, ?string $reviewNotes = null): LegalInstrumentField
    {
        if (! $this->canConfirm($actor)) {
            throw new AuthorizationException('Você não tem permissão para confirmar alterações do instrumento.');
        }

        $this->assertPending($proposal);

        return DB::transaction(function () use ($proposal, $actor, $reviewNotes): LegalInstrumentField {
            $proposal->refresh();
            $this->assertPending($proposal);

            $previous = $this->currentConfirmedVersion($proposal);

            $proposal->forceFill([
                'status' => LegalInstrumentFieldStatus::Confirmed,
                'supersedes_id' => $previous?->getKey(),
                'review_notes' => $this->normalizeText($reviewNotes),
                'reviewed_by' => $actor->getKey(),
                'reviewed_at' => now(),
            ])->save();

            $previous?->forceFill(['status' => LegalInstrumentFieldStatus::Superseded])->save();

            $event = $this->recordEvent($proposal, $previous, $actor);

            $this->pushToEmissionTimeline($event, $proposal, $previous, $actor);

            $this->audit(self::EVENT_CHANGE_CONFIRMED, 'Alteração do instrumento confirmada', $proposal, $actor, [
                'previous_value' => $previous?->formatted_value,
                'new_value' => $proposal->formatted_value,
                'previous_field_id' => $previous?->getKey(),
            ]);

            return $proposal->refresh();
        });
    }

    /**
     * Confirma várias propostas de uma vez — o "confirmar todas" da tela de
     * revisão de um aditamento.
     *
     * @param  Collection<int, LegalInstrumentField>  $proposals
     * @return Collection<int, LegalInstrumentField>
     */
    public function confirmMany(Collection $proposals, User $actor, ?string $reviewNotes = null): Collection
    {
        return $proposals->map(fn (LegalInstrumentField $proposal): LegalInstrumentField => $this->confirm($proposal, $actor, $reviewNotes));
    }

    public function reject(LegalInstrumentField $proposal, User $actor, ?string $reason): LegalInstrumentField
    {
        if (! $this->canReject($actor)) {
            throw new AuthorizationException('Você não tem permissão para rejeitar alterações do instrumento.');
        }

        $this->assertPending($proposal);

        $normalizedReason = $this->normalizeText($reason);

        if ($normalizedReason === null) {
            throw ValidationException::withMessages([
                'review_notes' => 'Informe o motivo da rejeição.',
            ]);
        }

        return DB::transaction(function () use ($proposal, $actor, $normalizedReason): LegalInstrumentField {
            $proposal->forceFill([
                'status' => LegalInstrumentFieldStatus::Rejected,
                'review_notes' => $normalizedReason,
                'reviewed_by' => $actor->getKey(),
                'reviewed_at' => now(),
            ])->save();

            $this->audit(self::EVENT_CHANGE_REJECTED, 'Alteração do instrumento rejeitada', $proposal, $actor, [
                'reason' => $normalizedReason,
            ]);

            return $proposal->refresh();
        });
    }

    /**
     * Propostas pendentes agrupadas pelo documento que as originou — é assim
     * que a revisão é apresentada: "o que o 3º aditamento mudou".
     *
     * @return Collection<int|string, Collection<int, LegalInstrumentField>>
     */
    public function pendingChangesByDocument(LegalInstrument $instrument): Collection
    {
        return $instrument->fields()
            ->with(['instrumentDocument.document', 'document', 'guarantee'])
            ->pendingReview()
            ->orderBy('field_key')
            ->get()
            ->groupBy(fn (LegalInstrumentField $field): int|string => $field->legal_instrument_document_id ?? 'sem-documento');
    }

    /**
     * Comparação proposta × vigente, para a tela de alterações (§21).
     *
     * @return array{previous: LegalInstrumentField|null, proposed: LegalInstrumentField, changed: bool}
     */
    public function describeChange(LegalInstrumentField $proposal): array
    {
        $previous = $this->currentConfirmedVersion($proposal);

        return [
            'previous' => $previous,
            'proposed' => $proposal,
            'changed' => ! $proposal->hasSameValueAs($previous),
        ];
    }

    /**
     * Versão confirmada vigente do mesmo campo — a que a proposta substituiria.
     *
     * O escopo do campo inclui a garantia: a matrícula da AFI e a matrícula de
     * outra garantia do mesmo instrumento são campos distintos.
     */
    private function currentConfirmedVersion(LegalInstrumentField $proposal): ?LegalInstrumentField
    {
        return LegalInstrumentField::query()
            ->where('legal_instrument_id', $proposal->legal_instrument_id)
            ->where('field_key', $proposal->field_key?->value)
            ->when(
                $proposal->guarantee_id === null,
                fn ($query) => $query->whereNull('guarantee_id'),
                fn ($query) => $query->where('guarantee_id', $proposal->guarantee_id),
            )
            ->where('status', LegalInstrumentFieldStatus::Confirmed->value)
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Registra o evento jurídico correspondente à alteração confirmada.
     *
     * Uma confirmação sem valor anterior é constituição, não alteração — a
     * primeira vez que um campo aparece não é uma mudança de nada.
     */
    private function recordEvent(
        LegalInstrumentField $proposal,
        ?LegalInstrumentField $previous,
        User $actor,
    ): LegalInstrumentEvent {
        $key = $proposal->field_key;

        $eventType = $previous === null
            ? LegalInstrumentEventType::Constitution
            : LegalInstrumentEventType::forFieldKey($key);

        $document = $proposal->instrumentDocument;

        return LegalInstrumentEvent::create([
            'legal_instrument_id' => $proposal->legal_instrument_id,
            'legal_instrument_document_id' => $proposal->legal_instrument_document_id,
            'guarantee_id' => $proposal->guarantee_id,
            'event_type' => $eventType,
            'effective_date' => $proposal->effective_date ?? $document?->document_date,
            'title' => $this->buildEventTitle($eventType, $document),
            'description' => $proposal->excerpt,
            'change_set' => [[
                'field' => $key?->value,
                'label' => $key?->label(),
                'from' => $previous?->formatted_value,
                'to' => $proposal->formatted_value,
                'clause' => $proposal->clause,
                'page' => $proposal->page,
            ]],
            'recorded_by' => $actor->getKey(),
        ]);
    }

    /**
     * Publica o evento na linha do tempo da emissão (§29 do escopo).
     *
     * Reutiliza o activity log que a aba "Histórico da Operação" já lê, em vez
     * de criar um segundo feed. Só eventos com significado jurídico sobem —
     * `Other` ficaria como ruído numa linha do tempo que hoje é legível.
     */
    private function pushToEmissionTimeline(
        LegalInstrumentEvent $event,
        LegalInstrumentField $proposal,
        ?LegalInstrumentField $previous,
        User $actor,
    ): void {
        // A linha do tempo da operação registra *alterações*, não a digitação
        // inicial: confirmar os campos do documento original geraria uma entrada
        // por campo e afogaria o histórico logo no cadastro do instrumento.
        if ($previous === null) {
            return;
        }

        if (! $event->event_type->isTimelineWorthy()) {
            return;
        }

        $instrument = $proposal->instrument;

        if ($instrument?->emission === null) {
            return;
        }

        activity()
            ->causedBy($actor)
            ->performedOn($instrument->emission)
            ->event('legal_instrument_change')
            ->withProperties([
                'attributes' => array_filter([
                    'Instrumento' => $instrument->display_name,
                    'Evento' => $event->event_type->label(),
                    'Campo' => $proposal->field_key?->label(),
                    'De' => $previous?->formatted_value,
                    'Para' => $proposal->formatted_value,
                    'Documento' => $event->instrumentDocument?->role_label,
                    'Vigência' => $event->effective_date?->format('d/m/Y'),
                ], static fn (mixed $value): bool => filled($value)),
            ])
            ->log($event->event_type->label());
    }

    private function buildEventTitle(LegalInstrumentEventType $eventType, ?LegalInstrumentDocument $document): string
    {
        if ($document === null) {
            return $eventType->label();
        }

        return sprintf('%s — %s', $eventType->label(), $document->role_label);
    }

    private function assertPending(LegalInstrumentField $proposal): void
    {
        if ($proposal->status === LegalInstrumentFieldStatus::PendingReview) {
            return;
        }

        throw ValidationException::withMessages([
            'change_review' => 'Esta alteração já foi revisada.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function audit(
        string $event,
        string $description,
        LegalInstrumentField $proposal,
        User $actor,
        array $properties,
    ): void {
        activity(self::LOG_NAME)
            ->causedBy($actor)
            ->performedOn($proposal)
            ->event($event)
            ->withProperties(array_merge([
                'legal_instrument_id' => $proposal->legal_instrument_id,
                'guarantee_id' => $proposal->guarantee_id,
                'field_key' => $proposal->field_key?->value,
                'field_label' => $proposal->field_key?->label(),
                'document_id' => $proposal->document_id,
                'clause' => $proposal->clause,
                'page' => $proposal->page,
            ], $properties))
            ->log($description);
    }

    private function normalizeText(?string $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
