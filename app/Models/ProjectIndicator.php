<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectIndicator extends Model
{
    protected $fillable = [
        'project_id',
        'financiamento_custo_obra_ideal',
        'financiamento_custo_obra_limite',
        'financiamento_vgv_ideal',
        'financiamento_vgv_limite',
        'custo_obra_vgv_ideal',
        'custo_obra_vgv_limite',
        'recebiveis_vfcto_ideal',
        'recebiveis_vfcto_limite',
        'recebiveis_terreno_vfcto_ideal',
        'recebiveis_terreno_vfcto_limite',
        'vendas_liquido_permutas_ideal',
        'vendas_liquido_permutas_limite',
        'terreno_vgv_ideal',
        'terreno_vgv_limite',
        'terreno_custo_obra_ideal',
        'terreno_custo_obra_limite',
        'ltv_ideal',
        'ltv_limite',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(ProposalProject::class, 'project_id');
    }
}
