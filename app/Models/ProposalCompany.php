<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProposalCompany extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'cnpj', 'ie', 'site', 'cep', 'logradouro', 
        'complemento', 'numero', 'bairro', 'cidade', 'estado'
    ];

    public function sectors()
    {
        return $this->belongsToMany(ProposalSector::class, 'proposal_company_sector', 'company_id', 'sector_id');
    }

    public function contacts()
    {
        return $this->hasMany(ProposalContact::class, 'company_id');
    }

    public function proposals()
    {
        return $this->hasMany(Proposal::class, 'company_id');
    }
}
