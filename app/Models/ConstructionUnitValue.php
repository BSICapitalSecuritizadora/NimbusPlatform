<?php

namespace App\Models;

use App\Concerns\MoneyFormatter;
use App\Enums\UnitValueSource;
use App\Services\SalesBoards\UnitValueResolver;
use Database\Factories\ConstructionUnitValueFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma atualização do valor comercial de uma unidade, com a data em que passou
 * a valer.
 *
 * Append-only: nada aqui é editado nem excluído no fluxo normal. Corrigir um
 * valor lançado errado é registrar outra linha para a mesma vigência -- a
 * anterior permanece, porque foi ela que esteve valendo enquanto ninguém sabia
 * do erro.
 *
 * Sem `effective_to`: a vigência de uma linha termina quando a próxima começa,
 * e quem decide isso é o {@see UnitValueResolver}.
 */
class ConstructionUnitValue extends Model
{
    /** @use HasFactory<ConstructionUnitValueFactory> */
    use HasFactory;

    protected $fillable = [
        'construction_unit_id',
        'value',
        'effective_from',
        'source',
        'reason',
        'created_by_id',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'effective_from' => 'date',
            'source' => UnitValueSource::class,
        ];
    }

    public function constructionUnit(): BelongsTo
    {
        return $this->belongsTo(ConstructionUnit::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * A importação que criou o registro em lote, quando foi o caso.
     *
     * Fora de `$fillable` e da trilha de propósito: é carimbada só pelo insert
     * em lote da importação e nunca muda depois. Registro criado à mão, ou antes
     * da coluna existir, não tem importação.
     *
     * @return BelongsTo<ImportRun, $this>
     */
    public function importRun(): BelongsTo
    {
        return $this->belongsTo(ImportRun::class, 'import_run_id');
    }

    public function getFormattedValueAttribute(): string
    {
        return MoneyFormatter::formatCurrencyForDisplay($this->value);
    }
}
