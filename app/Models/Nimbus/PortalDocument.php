<?php

namespace App\Models\Nimbus;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortalDocument extends Model
{
    use HasFactory;

    protected $table = 'nimbus_documents';

    protected $guarded = ['id'];

    public function portalUser(): BelongsTo
    {
        return $this->belongsTo(PortalUser::class, 'nimbus_portal_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
