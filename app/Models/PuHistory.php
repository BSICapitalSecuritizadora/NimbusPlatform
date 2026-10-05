<?php

namespace App\Models;

use App\Domain\PuCalculator\Services\EmissionPuReader;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Histórico de PU: dado legado, importado de planilha ou lançado à mão.
 *
 * Não é caminho de publicação da curva: desde a Fase 2 de governança nenhuma
 * geração grava aqui. Para uma emissão governada (com curva de PU), só valem
 * como PU as linhas que {@see EmissionPuReader}
 * reconhece como legado legítimo.
 */
class PuHistory extends Model
{
    use LogsActivity;

    /** Linha importada de planilha. */
    public const SOURCE_IMPORT = 'import';

    /** Linha lançada ou editada na tela. */
    public const SOURCE_MANUAL = 'manual';

    /** @var list<string> */
    public const LEGITIMATE_SOURCES = [
        self::SOURCE_IMPORT,
        self::SOURCE_MANUAL,
    ];

    protected $fillable = [
        'emission_id',
        'date',
        'unit_value',
        'source',
    ];

    protected $casts = [
        'date' => 'date',
        'unit_value' => 'decimal:6',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function emission(): BelongsTo
    {
        return $this->belongsTo(Emission::class);
    }
}
