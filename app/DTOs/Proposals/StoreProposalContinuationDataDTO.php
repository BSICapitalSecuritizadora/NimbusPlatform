<?php

namespace App\DTOs\Proposals;

readonly class StoreProposalContinuationDataDTO
{
    /**
     * @param  array<int, ProposalContinuationProjectDTO>  $projects
     * @param  array<int, ProposalContinuationUnitTypeDTO>  $unitTypes
     */
    public function __construct(
        public ProposalContinuationOverviewDTO $overview,
        public ProposalContinuationCharacteristicsDTO $characteristics,
        public array $projects,
        public array $unitTypes,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            overview: ProposalContinuationOverviewDTO::fromArray($data['overview'] ?? []),
            characteristics: ProposalContinuationCharacteristicsDTO::fromArray($data['characteristics'] ?? []),
            projects: array_map(
                static fn (array $project): ProposalContinuationProjectDTO => ProposalContinuationProjectDTO::fromArray($project),
                array_values($data['projects'] ?? []),
            ),
            unitTypes: array_map(
                static fn (array $unitType): ProposalContinuationUnitTypeDTO => ProposalContinuationUnitTypeDTO::fromArray($unitType),
                array_values($data['unit_types'] ?? []),
            ),
        );
    }

    public static function fromFlatPayload(array $validated): self
    {
        $projects = collect($validated['nome_empreendimento'] ?? [])
            ->values()
            ->map(function (mixed $name, int $index) use ($validated): array {
                return [
                    'id' => $validated['project_id'][$index] ?? null,
                    'name' => $name,
                    'exchanged_units' => $validated['unidades_permutadas'][$index] ?? 0,
                    'paid_units' => $validated['unidades_quitadas'][$index] ?? 0,
                    'unpaid_units' => $validated['unidades_nao_quitadas'][$index] ?? 0,
                    'stock_units' => $validated['unidades_estoque'][$index] ?? 0,
                    'incurred_cost' => $validated['custo_incidido'][$index] ?? null,
                    'cost_to_incur' => $validated['custo_a_incorrer'][$index] ?? null,
                    'paid_sales_value' => $validated['valor_quitadas'][$index] ?? null,
                    'unpaid_sales_value' => $validated['valor_nao_quitadas'][$index] ?? null,
                    'stock_sales_value' => $validated['valor_estoque'][$index] ?? null,
                    'received_value' => $validated['valor_ja_recebido'][$index] ?? null,
                    'value_until_keys' => $validated['valor_ate_chaves'][$index] ?? null,
                    'value_after_keys' => $validated['valor_chaves_pos'][$index] ?? null,
                ];
            })
            ->all();

        $unitTypes = collect($validated['tipo_total'] ?? [])
            ->values()
            ->map(function (mixed $totalUnits, int $index) use ($validated): array {
                return [
                    'total_units' => $totalUnits,
                    'bedrooms' => $validated['tipo_dormitorios'][$index] ?? null,
                    'parking_spaces' => $validated['tipo_vagas'][$index] ?? null,
                    'usable_area' => $validated['tipo_area'][$index] ?? null,
                    'average_price' => $validated['tipo_preco_medio'][$index] ?? null,
                ];
            })
            ->all();

        return self::fromArray([
            'overview' => [
                'development_name' => $validated['nome'] ?? '',
                'website_url' => $validated['site'] ?? null,
                'requested_amount' => $validated['valor_solicitado'] ?? 0,
                'land_market_value' => $validated['valor_mercado_terreno'] ?? null,
                'land_area' => $validated['area_terreno'] ?? 0,
                'launch_date' => $validated['data_lancamento'] ?? '',
                'sales_launch_date' => $validated['lancamento_vendas'] ?? '',
                'construction_start_date' => $validated['inicio_obras'] ?? '',
                'delivery_forecast_date' => $validated['previsao_entrega'] ?? '',
                'remaining_months' => $validated['prazo_remanescente'] ?? null,
                'zip_code' => $validated['cep'] ?? '',
                'street' => $validated['logradouro'] ?? '',
                'address_complement' => $validated['complemento'] ?? null,
                'address_number' => $validated['numero'] ?? '',
                'neighborhood' => $validated['bairro'] ?? '',
                'city' => $validated['cidade'] ?? '',
                'state' => $validated['estado'] ?? '',
            ],
            'characteristics' => [
                'blocks' => $validated['car_bloco'] ?? 0,
                'floors' => $validated['car_pavimentos'] ?? 0,
                'typical_floors' => $validated['car_andares_tipo'] ?? 0,
                'units_per_floor' => $validated['car_unidades_andar'] ?? 0,
                'total_units' => $validated['car_total'] ?? null,
            ],
            'projects' => $projects,
            'unit_types' => $unitTypes,
        ]);
    }
}
