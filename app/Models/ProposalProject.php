<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProposalProject extends Model
{
    use HasFactory;

    protected $fillable = [
        'proposal_id', 'name', 'company_name', 'site',
        'value_requested', 'land_market_value', 'land_area',
        'cep', 'logradouro', 'numero', 'complemento', 'bairro', 'cidade', 'estado',
        'launch_date', 'units_exchanged', 'units_paid', 'units_unpaid', 'units_stock', 'units_total',
        'sales_percentage', 'cost_incurred', 'cost_to_incur', 'cost_total', 'work_stage_percentage',
        'value_paid', 'value_unpaid', 'value_stock', 'value_total_sale', 'value_received', 'value_until_keys', 'value_post_keys'
    ];

    public function proposal()
    {
        return $this->belongsTo(Proposal::class);
    }

    public function characteristics()
    {
        return $this->hasOne(ProjectCharacteristic::class, 'project_id');
    }

    public function indicators()
    {
        return $this->hasOne(ProjectIndicator::class, 'project_id');
    }
}
