<?php

namespace App\Models\Nimbus;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubmissionNote extends Model
{
    use HasFactory;

    protected $table = 'nimbus_submission_notes';

    protected $guarded = ['id'];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class, 'nimbus_submission_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }

    public function getVisibilityLabelAttribute(): string
    {
        return match ($this->visibility) {
            'ADMIN_ONLY' => 'Comentário interno',
            'USER_VISIBLE' => 'Visível ao solicitante',
            default => (string) $this->visibility,
        };
    }

    public function getAuthorLabelAttribute(): string
    {
        if (filled($this->user?->name)) {
            return (string) $this->user->name;
        }

        if (filled($this->submission?->portalUser?->full_name)) {
            return $this->submission->portalUser->full_name.' (Solicitante do Portal)';
        }

        return 'Solicitante do Portal';
    }
}
