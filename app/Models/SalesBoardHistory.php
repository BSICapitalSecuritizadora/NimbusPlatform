<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesBoardHistory extends Model
{
    /** @use HasFactory<\Database\Factories\SalesBoardHistoryFactory> */
    use HasFactory;

    protected $fillable = [
        'sales_board_id',
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

    public function salesBoard(): BelongsTo
    {
        return $this->belongsTo(SalesBoard::class);
    }
}
