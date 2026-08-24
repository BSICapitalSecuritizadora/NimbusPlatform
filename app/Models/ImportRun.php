<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reconciliation that was confirmed: which file, by whom, and what it moved.
 *
 * A summary on purpose. What each contract or installment actually had before
 * the import changed it lives on that record's own activity log; this is the
 * index that says which run to go looking in.
 */
class ImportRun extends Model
{
    public const TYPE_CONTRACTS = 'contracts';

    public const TYPE_CONTRACT_INSTALLMENTS = 'contract-installments';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'type',
        'file_name',
        'checksum',
        'user_id',
        'contract_id',
        'records_analyzed',
        'records_created',
        'records_updated',
        'records_unchanged',
        'records_critical',
    ];

    protected function casts(): array
    {
        return [
            'records_analyzed' => 'integer',
            'records_created' => 'integer',
            'records_updated' => 'integer',
            'records_unchanged' => 'integer',
            'records_critical' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            self::TYPE_CONTRACTS => 'Contratos',
            self::TYPE_CONTRACT_INSTALLMENTS => 'Parcelas',
            default => $this->type,
        };
    }

    /**
     * Whether the run wrote anything. A run that found the position already
     * reconciled is worth recording precisely because it proves nothing moved.
     */
    public function madeChanges(): bool
    {
        return ($this->records_created + $this->records_updated) > 0;
    }
}
