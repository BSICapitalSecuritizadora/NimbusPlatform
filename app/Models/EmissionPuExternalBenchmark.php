<?php

namespace App\Models;

use App\Domain\PuCalculator\Enums\PuExternalBenchmarkStatus;
use Database\Factories\EmissionPuExternalBenchmarkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class EmissionPuExternalBenchmark extends Model
{
    /** @use HasFactory<EmissionPuExternalBenchmarkFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Imported external PU benchmarks are immutable.');
        });
        static::deleting(function (): never {
            throw new LogicException('Imported external PU benchmarks cannot be deleted.');
        });
    }

    protected $fillable = [
        'emission_id',
        'source_type',
        'source_name',
        'source_document_id',
        'source_evidence_id',
        'reference_as_of',
        'input_file_name',
        'file_sha256',
        'dataset_sha256',
        'import_identity_sha256',
        'row_count',
        'from_date',
        'to_date',
        'status',
        'created_by',
    ];

    protected $attributes = [
        'status' => PuExternalBenchmarkStatus::Ready->value,
    ];

    protected function casts(): array
    {
        return [
            'reference_as_of' => 'date',
            'from_date' => 'date',
            'to_date' => 'date',
            'row_count' => 'integer',
            'status' => PuExternalBenchmarkStatus::class,
        ];
    }

    public function emission(): BelongsTo
    {
        return $this->belongsTo(Emission::class);
    }

    public function sourceDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'source_document_id');
    }

    public function sourceEvidence(): BelongsTo
    {
        return $this->belongsTo(EmissionPuBaselineEvidence::class, 'source_evidence_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(EmissionPuExternalBenchmarkRow::class, 'benchmark_id');
    }

    public function validations(): HasMany
    {
        return $this->hasMany(EmissionPuExternalValidation::class, 'benchmark_id');
    }
}
