<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProposalSector extends Model
{
    use HasFactory;

    protected $fillable = ['name'];

    public function companies()
    {
        return $this->belongsToMany(ProposalCompany::class, 'proposal_company_sector', 'sector_id', 'company_id');
    }
}
