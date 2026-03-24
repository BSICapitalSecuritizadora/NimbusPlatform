<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProposalContact extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id', 'name', 'email', 'phone_personal', 
        'whatsapp', 'phone_company', 'cargo'
    ];

    public function company()
    {
        return $this->belongsTo(ProposalCompany::class, 'company_id');
    }

    public function proposals()
    {
        return $this->hasMany(Proposal::class, 'contact_id');
    }
}
