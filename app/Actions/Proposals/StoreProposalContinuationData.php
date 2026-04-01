<?php

namespace App\Actions\Proposals;

use App\Models\ProjectCharacteristic;
use App\Models\Proposal;
use App\Models\ProposalProject;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StoreProposalContinuationData
{
    public function __construct(
        protected UpdateProposalStatus $updateProposalStatus,
    ) {}

    /**
     * @param  array{
     *     overview: array<string, mixed>,
     *     characteristics: array<string, mixed>,
     *     projects: array<int, array<string, mixed>>,
     *     unit_types: array<int, array<string, mixed>>
     * }  $payload
     * @param  array<int, UploadedFile>  $files
     */
    public function handle(Proposal $proposal, array $payload, array $files = []): void
    {
        DB::transaction(function () use ($proposal, $payload, $files): void {
            $sharedPayload = [
                'development_name' => $payload['overview']['development_name'],
                'website_url' => $payload['overview']['website_url'] ?: null,
                'requested_amount' => ProposalProject::normalizeDecimalValue($payload['overview']['requested_amount']),
                'land_market_value' => $this->normalizeDecimalOrNull($payload['overview']['land_market_value'] ?? null),
                'land_area' => ProposalProject::normalizeDecimalValue($payload['overview']['land_area']),
                'zip_code' => $payload['overview']['zip_code'],
                'street' => $payload['overview']['street'],
                'address_number' => $payload['overview']['address_number'],
                'address_complement' => $payload['overview']['address_complement'] ?: null,
                'neighborhood' => $payload['overview']['neighborhood'],
                'city' => $payload['overview']['city'],
                'state' => $payload['overview']['state'],
                'launch_date' => $this->monthToDate($payload['overview']['launch_date']),
                'sales_launch_date' => $this->monthToDate($payload['overview']['sales_launch_date']),
                'construction_start_date' => $this->monthToDate($payload['overview']['construction_start_date']),
                'delivery_forecast_date' => $this->monthToDate($payload['overview']['delivery_forecast_date']),
            ];

            $remainingMonths = $this->calculateRemainingMonths(
                $sharedPayload['construction_start_date'],
                $sharedPayload['delivery_forecast_date'],
            );

            foreach ($payload['projects'] as $projectData) {
                $project = $this->upsertProposalProject($proposal, $projectData['id'] ?? null, [
                    ...$sharedPayload,
                    'name' => $projectData['name'],
                    'remaining_months' => $payload['overview']['remaining_months'] ?: $remainingMonths,
                    'exchanged_units' => $projectData['exchanged_units'] ?? 0,
                    'paid_units' => $projectData['paid_units'] ?? 0,
                    'unpaid_units' => $projectData['unpaid_units'] ?? 0,
                    'stock_units' => $projectData['stock_units'] ?? 0,
                    'incurred_cost' => $this->normalizeDecimalOrNull($projectData['incurred_cost'] ?? null),
                    'cost_to_incur' => $this->normalizeDecimalOrNull($projectData['cost_to_incur'] ?? null),
                    'paid_sales_value' => $this->normalizeDecimalOrNull($projectData['paid_sales_value'] ?? null),
                    'unpaid_sales_value' => $this->normalizeDecimalOrNull($projectData['unpaid_sales_value'] ?? null),
                    'stock_sales_value' => $this->normalizeDecimalOrNull($projectData['stock_sales_value'] ?? null),
                    'received_value' => $this->normalizeDecimalOrNull($projectData['received_value'] ?? null),
                    'value_until_keys' => $this->normalizeDecimalOrNull($projectData['value_until_keys'] ?? null),
                    'value_after_keys' => $this->normalizeDecimalOrNull($projectData['value_after_keys'] ?? null),
                ]);

                $this->syncProjectCharacteristics($project, $payload['characteristics'], $payload['unit_types']);
            }

            if ($files !== []) {
                $this->storeUploadedFiles($proposal, $files);
            }

            $proposal->forceFill([
                'completed_at' => now(),
            ])->save();

            if (in_array($proposal->status, [
                Proposal::STATUS_AWAITING_COMPLETION,
                Proposal::STATUS_AWAITING_INFORMATION,
            ], true)) {
                $this->updateProposalStatus->handle(
                    $proposal,
                    Proposal::STATUS_IN_REVIEW,
                    null,
                    'Informações complementares enviadas pelo proponente.',
                    false,
                );
            }
        });
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{
     *     overview: array<string, mixed>,
     *     characteristics: array<string, mixed>,
     *     projects: array<int, array<string, mixed>>,
     *     unit_types: array<int, array<string, mixed>>
     * }
     */
    public static function fromFlatPayload(array $validated): array
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

        return [
            'overview' => [
                'development_name' => $validated['nome'],
                'website_url' => $validated['site'] ?? null,
                'requested_amount' => $validated['valor_solicitado'],
                'land_market_value' => $validated['valor_mercado_terreno'] ?? null,
                'land_area' => $validated['area_terreno'],
                'launch_date' => $validated['data_lancamento'],
                'sales_launch_date' => $validated['lancamento_vendas'],
                'construction_start_date' => $validated['inicio_obras'],
                'delivery_forecast_date' => $validated['previsao_entrega'],
                'remaining_months' => $validated['prazo_remanescente'] ?? null,
                'zip_code' => $validated['cep'],
                'street' => $validated['logradouro'],
                'address_complement' => $validated['complemento'] ?? null,
                'address_number' => $validated['numero'],
                'neighborhood' => $validated['bairro'],
                'city' => $validated['cidade'],
                'state' => $validated['estado'],
            ],
            'characteristics' => [
                'blocks' => $validated['car_bloco'],
                'floors' => $validated['car_pavimentos'],
                'typical_floors' => $validated['car_andares_tipo'],
                'units_per_floor' => $validated['car_unidades_andar'],
                'total_units' => $validated['car_total'] ?? null,
            ],
            'projects' => $projects,
            'unit_types' => $unitTypes,
        ];
    }

    protected function monthToDate(string $value): string
    {
        return Carbon::createFromFormat('Y-m', $value)->startOfMonth()->toDateString();
    }

    protected function normalizeDecimalOrNull(mixed $value): ?float
    {
        if (blank($value)) {
            return null;
        }

        return ProposalProject::normalizeDecimalValue($value);
    }

    protected function calculateRemainingMonths(string $startDate, string $endDate): int
    {
        return Carbon::parse($startDate)->diffInMonths(Carbon::parse($endDate));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function upsertProposalProject(Proposal $proposal, null|string|int $projectId, array $attributes): ProposalProject
    {
        if (blank($projectId)) {
            return $proposal->projects()->create($attributes);
        }

        $project = $proposal->projects()->whereKey($projectId)->first();

        if (! $project) {
            throw ValidationException::withMessages([
                'projects' => 'Um dos empreendimentos enviados não pertence a esta proposta.',
            ]);
        }

        $project->fill($attributes);
        $project->save();

        return $project;
    }

    /**
     * @param  array<string, mixed>  $characteristics
     * @param  array<int, array<string, mixed>>  $unitTypes
     */
    protected function syncProjectCharacteristics(
        ProposalProject $project,
        array $characteristics,
        array $unitTypes,
    ): void {
        /** @var ProjectCharacteristic $characteristic */
        $characteristic = $project->characteristics()->firstOrNew();
        $characteristic->fill([
            'blocks' => $characteristics['blocks'],
            'floors' => $characteristics['floors'],
            'typical_floors' => $characteristics['typical_floors'],
            'units_per_floor' => $characteristics['units_per_floor'],
            'total_units' => $characteristics['total_units']
                ?? ($characteristics['blocks'] * $characteristics['typical_floors'] * $characteristics['units_per_floor']),
        ]);
        $characteristic->save();

        $characteristic->unitTypes()->delete();

        foreach ($unitTypes as $typeIndex => $unitType) {
            $averagePrice = ProposalProject::normalizeDecimalValue($unitType['average_price'] ?? null);
            $usableArea = (float) ($unitType['usable_area'] ?? 0);

            $characteristic->unitTypes()->create([
                'order' => $typeIndex + 1,
                'total_units' => $unitType['total_units'],
                'bedrooms' => $unitType['bedrooms'] ?? null,
                'parking_spaces' => $unitType['parking_spaces'] ?? null,
                'usable_area' => $usableArea,
                'average_price' => $averagePrice,
                'price_per_square_meter' => $usableArea > 0 ? round($averagePrice / $usableArea, 2) : 0,
            ]);
        }
    }

    /**
     * @param  array<int, UploadedFile>  $files
     */
    protected function storeUploadedFiles(Proposal $proposal, array $files): void
    {
        foreach ($files as $file) {
            $storedPath = $file->store("proposal-files/{$proposal->id}", 'local');

            $proposal->files()->create([
                'disk' => 'local',
                'file_path' => $storedPath,
                'file_name' => basename($storedPath),
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
            ]);
        }
    }
}
