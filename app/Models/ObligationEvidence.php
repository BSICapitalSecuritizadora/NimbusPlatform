<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Number;

class ObligationEvidence extends Model
{
    /** @use HasFactory<\Database\Factories\ObligationEvidenceFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'obligation_evidences';

    protected $fillable = [
        'obligation_id',
        'emission_id',
        'uploaded_by',
        'original_name',
        'path',
        'disk',
        'mime_type',
        'size',
        'description',
        'uploaded_at',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'uploaded_at' => 'datetime',
        ];
    }

    public function getHumanSizeAttribute(): string
    {
        return $this->size ? Number::fileSize($this->size) : '—';
    }

    public function obligation(): BelongsTo
    {
        return $this->belongsTo(Obligation::class);
    }

    public function emission(): BelongsTo
    {
        return $this->belongsTo(Emission::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
