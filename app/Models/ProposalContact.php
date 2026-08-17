<?php

namespace App\Models;

use App\Support\PhoneNormalizer;
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
        'is_whatsapp',
        'whatsapp_contact_consent',
        'phone_company',
        'cargo',
    ];

    protected function casts(): array
    {
        return [
            'whatsapp' => 'boolean',
            'is_whatsapp' => 'boolean',
            'whatsapp_contact_consent' => 'boolean',
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
                        ? 'Pessoal: '.$this->phone_personal.($this->is_whatsapp === true ? ' (WhatsApp)' : '')
                        : null,
                    $this->phone_company ? 'Empresa: '.$this->phone_company : null,
                ])->filter();

                return $phones->isNotEmpty() ? $phones->implode(' | ') : '—';
            },
        );
    }

    protected function whatsappUrl(): Attribute
    {
        return Attribute::make(
            get: function (): ?string {
                if ($this->is_whatsapp !== true) {
                    return null;
                }

                $phone = PhoneNormalizer::forWhatsApp($this->phone_personal);

                return $phone ? "https://wa.me/{$phone}" : null;
            },
        );
    }

    protected function whatsappAvailabilityLabel(): Attribute
    {
        return Attribute::make(get: fn (): string => match ($this->is_whatsapp) {
            true => 'Sim',
            false => 'Não',
            null => 'Não informado (registro histórico)',
        });
    }

    protected function whatsappConsentLabel(): Attribute
    {
        return Attribute::make(get: fn (): string => match ($this->whatsapp_contact_consent) {
            true => 'Autorizado',
            false => 'Não autorizado',
            null => 'Não informado (registro histórico)',
        });
    }
}
