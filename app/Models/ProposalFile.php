<?php

namespace App\Models;

use App\Enums\MalwareScanStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProposalFile extends Model
{
    use HasFactory;

    protected $attributes = [
        'scan_status' => MalwareScanStatus::Pending->value,
    ];

    protected $fillable = [
        'proposal_id',
        'disk',
        'file_path',
        'file_name',
        'original_name',
        'mime_type',
        'file_size',
        'checksum',
        'scan_status',
    ];

    protected function casts(): array
    {
        return [
            'scan_status' => MalwareScanStatus::class,
        ];
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }

    protected function scanStatusLabel(): Attribute
    {
        return Attribute::make(get: fn (): string => $this->scan_status->label());
    }

    protected function isDownloadable(): Attribute
    {
        return Attribute::make(get: fn (): bool => $this->scan_status === MalwareScanStatus::Clean);
    }
}
