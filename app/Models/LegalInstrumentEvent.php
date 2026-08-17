<?php

namespace App\Models;

use App\Enums\LegalInstrumentEventType;
use Database\Factories\LegalInstrumentEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evento jurídico do instrumento (§13 do escopo).
 *
 * Nasce da confirmação de uma alteração, nunca da extração sozinha. `change_set`
 * guarda o "de → para" já resolvido, para que o histórico e a linha do tempo
 * não precisem reconsultar as versões de campo para se desenhar.
 */
class LegalInstrumentEvent extends Model
{
    /** @use HasFactory<LegalInstrumentEventFactory> */
    use HasFactory;

    protected $fillable = [
        'legal_instrument_id',
        'legal_instrument_document_id',
        'guarantee_id',
        'event_type',
        'effective_date',
        'title',
        'description',
        'change_set',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => LegalInstrumentEventType::class,
            'effective_date' => 'date:Y-m-d',
            'change_set' => 'array',
        ];
    }

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(LegalInstrument::class, 'legal_instrument_id');
    }

    public function instrumentDocument(): BelongsTo
    {
        return $this->belongsTo(LegalInstrumentDocument::class, 'legal_instrument_document_id');
    }

    public function guarantee(): BelongsTo
    {
        return $this->belongsTo(Guarantee::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * @return array<int, array{field: string, label: string, from: string|null, to: string|null}>
     */
    public function getChangeListAttribute(): array
    {
        return array_values(array_filter(
            $this->change_set ?? [],
            static fn (mixed $change): bool => is_array($change) && isset($change['label']),
        ));
    }
}
