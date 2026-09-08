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
        'paid_amount',
        'due_date',
        'payment_date',
        'status',
        'conta_azul_bill_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'due_date' => 'date',
            'payment_date' => 'date',
        ];
    }

    public function effectivePaymentDate(): ?CarbonInterface
    {
        return $this->payment_date;
    }

    public function isFullyPaid(): bool
    {
        if ($this->status !== null) {
            return in_array(strtolower($this->status), [
                'paid',
                'pago',
                'quitado',
                'recebido',
            ], true);
        }

        // Sem status explícito, só é considerado pago se houver data de pagamento comprovada
        return $this->payment_date !== null;
    }

    public function isPartiallyPaid(): bool
    {
        if ($this->status !== null) {
            return in_array(strtolower($this->status), [
                'partially_paid',
                'parcialmente_pago',
                'recebido_parcial',
            ], true);
        }

        return false;
    }

    public function isPaid(): bool
    {
        return $this->isFullyPaid();
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }
}
