<?php

namespace App\Models;

use Database\Factories\ObligationNotificationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ObligationNotification extends Model
{
    /** @use HasFactory<ObligationNotificationFactory> */
    use HasFactory;

    public const TYPE_DUE_SOON = 'due_soon';

    public const TYPE_DUE_TODAY = 'due_today';

    public const TYPE_OVERDUE = 'overdue';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_PROCESSING = 'processing';

    protected $fillable = [
        'obligation_id',
        'emission_id',
        'notification_type',
        'milestone',
        'deduplication_key',
        'recipient',
        'status',
        'error_message',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    public function obligation(): BelongsTo
    {
        return $this->belongsTo(Obligation::class);
    }

    public function emission(): BelongsTo
    {
        return $this->belongsTo(Emission::class);
    }
}
