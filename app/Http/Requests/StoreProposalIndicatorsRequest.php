<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProposalIndicatorsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'financiamento_custo_obra_ideal' => ['nullable', 'numeric'],
            'financiamento_custo_obra_limite' => ['nullable', 'numeric'],
            'financiamento_vgv_ideal' => ['nullable', 'numeric'],
            'financiamento_vgv_limite' => ['nullable', 'numeric'],
            'custo_obra_vgv_ideal' => ['nullable', 'numeric'],
            'custo_obra_vgv_limite' => ['nullable', 'numeric'],
            'recebiveis_vfcto_ideal' => ['nullable', 'numeric'],
            'recebiveis_vfcto_limite' => ['nullable', 'numeric'],
            'recebiveis_terreno_vfcto_ideal' => ['nullable', 'numeric'],
            'recebiveis_terreno_vfcto_limite' => ['nullable', 'numeric'],
            'vendas_liquido_permutas_ideal' => ['nullable', 'numeric'],
            'vendas_liquido_permutas_limite' => ['nullable', 'numeric'],
            'terreno_vgv_ideal' => ['nullable', 'numeric'],
            'terreno_vgv_limite' => ['nullable', 'numeric'],
            'terreno_custo_obra_ideal' => ['nullable', 'numeric'],
            'terreno_custo_obra_limite' => ['nullable', 'numeric'],
            'ltv_ideal' => ['nullable', 'numeric'],
            'ltv_limite' => ['nullable', 'numeric'],
        ];
    }

    public function messages(): array
    {
        return [
            'financiamento_custo_obra_ideal.numeric' => 'Os indicadores devem ser informados com números válidos.',
        ];
    }
}
