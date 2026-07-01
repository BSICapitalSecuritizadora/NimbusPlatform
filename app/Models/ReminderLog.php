<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ReminderLog extends Model
{
    protected $fillable = [
        'type',
        'channel',
        'status',
        'notifiable_type',
        'notifiable_id',
        'related_type',
        'related_id',
        'recipient_email',
        'severity',
        'reason',
        'error_message',
        'sent_at',
        'payload',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'payload' => 'array',
    ];

    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }

    public function related(): MorphTo
    {
        return $this->morphTo();
    }
}
