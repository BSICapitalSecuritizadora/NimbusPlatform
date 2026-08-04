<?php

namespace App\Models\Nimbus;

use App\Concerns\DerivesStoredFileMetadata;
use App\Concerns\ScansUploadedFile;
use App\Enums\MalwareScanStatus;
use App\Models\User;
use App\Services\DocumentStorageService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortalDocument extends Model
{
    use DerivesStoredFileMetadata;
    use HasFactory;
    use ScansUploadedFile;

    protected $table = 'nimbus_documents';

    protected $guarded = ['id'];

    protected $attributes = [
        'scan_status' => MalwareScanStatus::Pending->value,
    ];

    protected function casts(): array
    {
        return [
            'scan_status' => MalwareScanStatus::class,
        ];
    }

    public function uploadedFileDisk(): string
    {
        return DocumentStorageService::privateDisk();
    }

    public function portalUser(): BelongsTo
    {
        return $this->belongsTo(PortalUser::class, 'nimbus_portal_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
