<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Proposal extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id', 'contact_id', 'observations', 'status'
    ];

    public function company()
    {
        return $this->belongsTo(ProposalCompany::class, 'company_id');
    }

    public function contact()
    {
        return $this->belongsTo(ProposalContact::class, 'contact_id');
    }

    public function project()
    {
        return $this->hasOne(ProposalProject::class);
    }
}
