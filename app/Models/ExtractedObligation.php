<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ExtractedObligation extends Model
{
    /** @use HasFactory<\Database\Factories\ExtractedObligationFactory> */
    use HasFactory;

    public const STATUS_OPTIONS = [
        'suggested' => 'Sugerida',
        'approved' => 'Aprovada',
        'rejected' => 'Rejeitada',
    ];

    public const PRIORITY_OPTIONS = [
        'low' => 'Baixa',
        'medium' => 'Média',
        'high' => 'Alta',
        'critical' => 'Crítica',
    ];

    protected $fillable = [
        'emission_id',
        'document_id',
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
        'confidence_score',
        'review_notes',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'reviewed_at' => 'datetime',
            'confidence_score' => 'float',
            'source_page' => 'integer',
        ];
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_OPTIONS[$this->status] ?? $this->status;
    }

    public function getPriorityLabelAttribute(): string
    {
        return self::PRIORITY_OPTIONS[$this->priority] ?? $this->priority;
    }

    public function confidencePercent(): ?string
    {
        if ($this->confidence_score === null) {
            return null;
        }

        return round($this->confidence_score * 100).'%';
    }

    public function emission(): BelongsTo
    {
        return $this->belongsTo(Emission::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function obligation(): HasOne
    {
        return $this->hasOne(Obligation::class);
    }
}
