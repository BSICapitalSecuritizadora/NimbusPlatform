<?php

namespace App\Models\Nimbus;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

class PortalUser extends Authenticatable
{
    use \Illuminate\Database\Eloquent\Factories\HasFactory;

    protected $table = 'nimbus_portal_users';

    protected $guarded = ['id'];

    protected $casts = [
        'last_login_at' => 'datetime',
    ];

    public function accessTokens(): HasMany
    {
        return $this->hasMany(AccessToken::class, 'nimbus_portal_user_id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class, 'nimbus_portal_user_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(PortalDocument::class, 'nimbus_portal_user_id');
    }
}
