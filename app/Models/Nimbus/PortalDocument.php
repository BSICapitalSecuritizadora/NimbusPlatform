<?php

namespace App\Models\Nimbus;

use Illuminate\Database\Eloquent\Model;

class PortalDocument extends Model
{
    use \Illuminate\Database\Eloquent\Factories\HasFactory;

    protected $table = 'nimbus_documents';

    protected $guarded = ['id'];

    public function portalUser()
    {
        return $this->belongsTo(PortalUser::class, 'nimbus_portal_user_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by_user_id');
    }
}
