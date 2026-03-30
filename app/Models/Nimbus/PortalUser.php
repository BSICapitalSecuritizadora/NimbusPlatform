<?php

namespace App\Models\Nimbus;

use Illuminate\Database\Eloquent\Model;

class PortalUser extends Model
{
    use \Illuminate\Database\Eloquent\Factories\HasFactory;

    protected $table = 'nimbus_portal_users';

    protected $guarded = ['id'];

    protected $casts = [
        'last_login_at' => 'datetime',
    ];

    public function accessTokens()
    {
        return $this->hasMany(AccessToken::class, 'nimbus_portal_user_id');
    }

    public function submissions()
    {
        return $this->hasMany(Submission::class, 'nimbus_portal_user_id');
    }

    public function documents()
    {
        return $this->hasMany(PortalDocument::class, 'nimbus_portal_user_id');
    }
}
