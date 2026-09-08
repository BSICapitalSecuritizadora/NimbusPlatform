<?php

namespace App\Models;

use App\Enums\SalesBoardRolloutRecipientRole;
use Database\Factories\SalesBoardRolloutRecipientFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Quem recebe os avisos da automação de uma Emissão.
 *
 * Configuração, não autorização: estar aqui diz para quem o aviso vai, e não o
 * que a pessoa pode fazer. Quem abre a tela continua passando pelas permissões
 * de sempre.
 */
class SalesBoardRolloutRecipient extends Model
{
    /** @use HasFactory<SalesBoardRolloutRecipientFactory> */
    use HasFactory;

    protected $fillable = [
        'emission_id',
        'role',
        'user_id',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'role' => SalesBoardRolloutRecipientRole::class,
        ];
    }

    public function emission(): BelongsTo
    {
        return $this->belongsTo(Emission::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeForRole(Builder $query, SalesBoardRolloutRecipientRole $role): void
    {
        $query->where('role', $role);
    }
}
