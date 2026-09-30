<?php

namespace App\Models;

use App\Enums\SalesBoardRolloutRecipientRole;
use Database\Factories\SalesBoardRolloutRecipientFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

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
    use HasFactory, LogsActivity;

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

    /**
     * Quem entrou e quem saiu da lista de avisos, e quem fez a mudança.
     *
     * A remoção apaga a linha. Sem esta trilha não sobraria registro de que a
     * pessoa um dia recebeu os avisos de governança da Emissão, nem de quem a
     * tirou. Grava em `sales_board`, a categoria protegida do módulo.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('sales_board')
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
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
