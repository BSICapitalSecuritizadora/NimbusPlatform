<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ProposalSector extends Model
{
    use HasFactory;

    /**
     * Sectors offered on the public proposal form in every environment.
     *
     * @var list<string>
     */
    public const OFFICIAL_NAMES = ['Imobiliário', 'Agronegócio', 'Outros'];

    protected $fillable = ['name', 'is_active'];

    /**
     * Create any missing official sector without touching existing records.
     */
    public static function provisionOfficialSectors(): void
    {
        foreach (self::OFFICIAL_NAMES as $name) {
            self::query()->firstOrCreate(['name' => $name], ['is_active' => true]);
        }
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(ProposalCompany::class, 'proposal_company_sector', 'sector_id', 'company_id');
    }

    /**
     * @param  Builder<ProposalSector>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
