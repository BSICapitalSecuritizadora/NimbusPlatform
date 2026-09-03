<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpenseHistory extends Model
{
    protected $fillable = [
        'expense_id',
        'amount',
        'due_date',
        'payment_date',
        'conta_azul_bill_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'due_date' => 'date',
            'payment_date' => 'date',
        ];
    }

    public function effectivePaymentDate(): ?CarbonInterface
    {
        if ($this->payment_date !== null) {
            return $this->payment_date;
        }

        return $this->due_date;
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }
}
