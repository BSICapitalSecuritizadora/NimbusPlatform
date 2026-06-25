<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ObligationComment extends Model
{
    /** @use HasFactory<\Database\Factories\ObligationCommentFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'obligation_id',
        'emission_id',
        'user_id',
        'body',
        'is_internal',
        'edited_at',
        'edited_by',
    ];

    protected function casts(): array
    {
        return [
            'is_internal' => 'boolean',
            'edited_at' => 'datetime',
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

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }
}
