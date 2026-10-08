<?php

namespace App\Models;

use App\Domain\PuCalculator\Enums\PuAmortizationType;
use App\Domain\PuCalculator\Enums\PuEventDateChangeReason;
use App\Domain\PuCalculator\Enums\PuEventStatus;
use App\Domain\PuCalculator\Enums\PuEventType;
use App\Observers\PuContractualInputObserver;
use Carbon\CarbonImmutable;
use Database\Factories\EmissionPuEventFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

#[ObservedBy(PuContractualInputObserver::class)]
class EmissionPuEvent extends Model
{
    /** @use HasFactory<EmissionPuEventFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'active',
    ];

    protected $fillable = [
        'emission_id',
        'event_type',
        'status',
        'original_date',
        'effective_date',
        'effective_until',
        'effective_date_reason',
        'effective_date_justification',
        'effective_date_evidence_reference',
        'effective_date_evidence_excerpt',
        'amortization_type',
        'amortization_value',
        'financial_effect',
        'sequence',
        'description',
        'document_reference',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
    ];

    /**
     * Regras de domínio do evento contratual, valendo para qualquer caminho de
     * gravação -- formulário, cronograma contratual, materialização, importação:
     *
     *  - o tipo é do catálogo ({@see PuEventType});
     *  - vigência só em evento com duração, e nunca terminando antes de começar;
     *  - alteração de spread informa o novo spread;
     *  - um pagamento de juros ativo por data (a engine paga uma vez só);
     *  - cancelado é evidência: não volta a ativo e não muda mais, e o
     *    cancelamento registra quando e por quê;
     *  - evento que já entrou no retrato de uma versão de curva não é apagado, só
     *    cancelado.
     *
     * A identidade (tipo, data efetiva, sequência) entre os ativos é garantida pela
     * unique do banco.
     */
    protected static function booted(): void
    {
        static::saving(function (self $event): void {
            $event->assertDomainRules();
        });

        static::deleting(function (self $event): void {
            if ($event->getRawOriginal('governed_at') !== null) {
                throw ValidationException::withMessages([
                    'event_type' => 'Este evento já entrou no retrato de insumos de uma versão de curva e não pode ser excluído: cancele-o, registrando o motivo.',
                ]);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => PuEventStatus::class,
            'original_date' => 'date',
            'effective_date' => 'date',
            'effective_until' => 'date',
            'effective_date_reason' => PuEventDateChangeReason::class,
            'amortization_value' => 'decimal:16',
            'financial_effect' => 'array',
            'sequence' => 'integer',
            'governed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function emission(): BelongsTo
    {
        return $this->belongsTo(Emission::class);
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * @param  Builder<EmissionPuEvent>  $query
     * @return Builder<EmissionPuEvent>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', PuEventStatus::Active->value);
    }

    /**
     * Evento em memória sem status (cenário de simulação) conta como ativo.
     */
    public function isActive(): bool
    {
        return ($this->status ?? PuEventStatus::Active) === PuEventStatus::Active;
    }

    public function isGoverned(): bool
    {
        return $this->governed_at !== null;
    }

    /**
     * A data efetiva saiu da data original do contrato e ninguém registrou por quê.
     */
    public function hasUnjustifiedDateChange(): bool
    {
        return $this->original_date !== null
            && $this->effective_date !== null
            && ! $this->original_date->isSameDay($this->effective_date)
            && $this->effective_date_reason === null;
    }

    public function getEventTypeEnumAttribute(): PuEventType
    {
        return PuEventType::from((string) $this->event_type);
    }

    public function getAmortizationTypeEnumAttribute(): PuAmortizationType
    {
        return PuAmortizationType::from((string) $this->amortization_type);
    }

    private function assertDomainRules(): void
    {
        $errors = [];
        $type = PuEventType::tryFrom((string) $this->event_type);
        $originalStatus = PuEventStatus::tryFrom((string) $this->getRawOriginal('status'));

        if ($this->exists && $originalStatus === PuEventStatus::Cancelled) {
            throw ValidationException::withMessages([
                'event_type' => 'Evento cancelado é evidência e não pode mais ser alterado. Cadastre um evento novo, se for o caso.',
            ]);
        }

        if (! $type instanceof PuEventType) {
            $errors['event_type'] = sprintf('Tipo de evento de PU desconhecido: %s.', (string) $this->event_type);
        }

        if ($this->effective_date === null) {
            $errors['effective_date'] = 'Informe a data efetiva do evento.';
        }

        if ($this->effective_until !== null && $type instanceof PuEventType && ! $type->supportsDuration()) {
            $errors['effective_until'] = sprintf('%s é um evento instantâneo e não tem data final de vigência.', $type->label());
        }

        if ($this->effective_until !== null && $this->effective_date !== null && $this->effective_until->lt($this->effective_date)) {
            $errors['effective_until'] = 'A vigência do evento não pode terminar antes de começar.';
        }

        if ($type === PuEventType::SpreadAmendment) {
            $rate = is_array($this->financial_effect) ? ($this->financial_effect['spread_rate'] ?? null) : null;

            if (! is_numeric($rate)) {
                $errors['financial_effect'] = 'A alteração de spread precisa informar o novo spread (% a.a.).';
            }
        }

        if ($this->status === PuEventStatus::Cancelled
            && ($this->cancelled_at === null || blank($this->cancellation_reason))) {
            $errors['cancellation_reason'] = 'O cancelamento registra quando e por quê.';
        }

        if ($type === PuEventType::InterestPayment
            && $this->isActive()
            && $this->effective_date !== null
            && $this->hasActiveInterestPaymentOnSameDate()) {
            $errors['effective_date'] = sprintf(
                'Já existe um pagamento de juros ativo em %s: a engine paga os juros uma vez só por data.',
                CarbonImmutable::instance($this->effective_date)->format('d/m/Y'),
            );
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function hasActiveInterestPaymentOnSameDate(): bool
    {
        return self::query()
            ->where('emission_id', $this->emission_id)
            ->active()
            ->where('event_type', PuEventType::InterestPayment->value)
            ->whereDate('effective_date', CarbonImmutable::instance($this->effective_date)->toDateString())
            ->when($this->exists, fn (Builder $query): Builder => $query->whereKeyNot($this->getKey()))
            ->exists();
    }
}
