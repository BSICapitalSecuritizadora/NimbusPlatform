<?php

namespace App\Models\Nimbus;

use Illuminate\Database\Eloquent\Model;

class AccessToken extends Model
{
    use \Illuminate\Database\Eloquent\Factories\HasFactory;

    protected $table = 'nimbus_access_tokens';

    protected $guarded = ['id'];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    public function portalUser()
    {
        return $this->belongsTo(PortalUser::class, 'nimbus_portal_user_id');
    }
}
