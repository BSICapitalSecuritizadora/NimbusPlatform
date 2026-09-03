<?php

namespace App\Models;

use Database\Factories\EmissionPuExternalBenchmarkRowFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class EmissionPuExternalBenchmarkRow extends Model
{
    /** @use HasFactory<EmissionPuExternalBenchmarkRowFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        $immutable = function (): never {
            throw new LogicException('External PU benchmark rows are immutable.');
        };

        static::updating($immutable);
        static::deleting($immutable);
    }

    protected $fillable = [
        'benchmark_id',
        'reference_date',
        'unit_value',
    ];

    protected function casts(): array
    {
        return [
            'reference_date' => 'date',
        ];
    }

    public function benchmark(): BelongsTo
    {
        return $this->belongsTo(EmissionPuExternalBenchmark::class, 'benchmark_id');
    }
}
