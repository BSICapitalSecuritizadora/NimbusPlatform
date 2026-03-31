<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProposalCompany extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'cnpj',
        'ie',
        'site',
        'cep',
        'logradouro',
        'complemento',
        'numero',
        'bairro',
        'cidade',
        'estado',
    ];

    public function sectors(): BelongsToMany
    {
        return $this->belongsToMany(ProposalSector::class, 'proposal_company_sector', 'company_id', 'sector_id');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(ProposalContact::class, 'company_id');
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(Proposal::class, 'company_id');
    }

    protected function fullAddress(): Attribute
    {
        return Attribute::make(
            get: function (): string {
                $street = trim(implode(', ', array_filter([
                    $this->logradouro,
                    $this->numero,
                ])));

                $district = $this->bairro ? " - {$this->bairro}" : '';
                $city = trim(implode('/', array_filter([
                    $this->cidade,
                    $this->estado,
                ])));
                $zip = $this->cep ? " - CEP: {$this->cep}" : '';

                $address = trim($street.$district);

                if ($city !== '') {
                    $address = trim($address.'. '.$city, '. ');
                }

                return $address !== '' ? $address.$zip : '—';
            },
        );
    }
}
