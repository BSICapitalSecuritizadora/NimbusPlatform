<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProposalContact extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'name',
        'email',
        'phone_personal',
        'whatsapp',
        'phone_company',
        'cargo',
    ];

    protected function casts(): array
    {
        return [
            'whatsapp' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(ProposalCompany::class, 'company_id');
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(Proposal::class, 'contact_id');
    }

    protected function phoneSummary(): Attribute
    {
        return Attribute::make(
            get: function (): string {
                $phones = collect([
                    $this->phone_personal
                        ? 'Pessoal: '.$this->phone_personal.($this->whatsapp ? ' (WhatsApp)' : '')
                        : null,
                    $this->phone_company ? 'Empresa: '.$this->phone_company : null,
                ])->filter();

                return $phones->isNotEmpty() ? $phones->implode(' | ') : '—';
            },
        );
    }
}
