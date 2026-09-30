<?php

namespace App\Models;

use Database\Factories\SalesBoardHistoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class SalesBoardHistory extends Model
{
    /** @use HasFactory<SalesBoardHistoryFactory> */
    use HasFactory, LogsActivity;

    protected $fillable = [
        'sales_board_id',
        'is_initial',
        'changed_by_id',
        'change_reason',
        'reference_month',
        'stock_units',
        'financed_units',
        'paid_units',
        'exchanged_units',
        'total_units',
        'stock_value',
        'financed_value',
        'paid_value',
        'exchanged_value',
    ];

    protected function casts(): array
    {
        return [
            'is_initial' => 'boolean',
            'reference_month' => 'date',
            'stock_units' => 'integer',
            'financed_units' => 'integer',
            'paid_units' => 'integer',
            'exchanged_units' => 'integer',
            'total_units' => 'integer',
            'stock_value' => 'decimal:2',
            'financed_value' => 'decimal:2',
            'paid_value' => 'decimal:2',
            'exchanged_value' => 'decimal:2',
        ];
    }

    /**
     * Mesma categoria do {@see SalesBoard}: o histórico de versões é indivisível.
     * A cascata que apaga as versões junto com o quadro legado não dispara
     * evento, então o `created` gravado aqui é o que resta da posição inicial
     * consolidada -- e ele não pode expirar em um ano.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('sales_board')
            ->logOnly(['sales_board_id', 'is_initial', 'changed_by_id', 'change_reason', 'reference_month', 'stock_units', 'financed_units', 'paid_units', 'exchanged_units', 'stock_value', 'financed_value', 'paid_value', 'exchanged_value'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function salesBoard(): BelongsTo
    {
        return $this->belongsTo(SalesBoard::class);
    }

    /**
     * @param  Builder<SalesBoardHistory>  $query
     */
    public function scopeInitial(Builder $query): void
    {
        $query->where('is_initial', true);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_id');
    }

    public function hasChangeReason(): bool
    {
        return filled($this->change_reason);
    }
}
