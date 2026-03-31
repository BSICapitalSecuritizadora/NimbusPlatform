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
     *     operation: array<string, mixed>,
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
                'company_name' => $payload['operation']['nome'],
                'site' => $payload['operation']['site'] ?: null,
                'value_requested' => ProposalProject::normalizeDecimalValue($payload['operation']['valor_solicitado']),
                'land_market_value' => $this->normalizeDecimalOrNull($payload['operation']['valor_mercado_terreno'] ?? null),
                'land_area' => ProposalProject::normalizeDecimalValue($payload['operation']['area_terreno']),
                'cep' => $payload['operation']['cep'],
                'logradouro' => $payload['operation']['logradouro'],
                'numero' => $payload['operation']['numero'],
                'complemento' => $payload['operation']['complemento'] ?: null,
                'bairro' => $payload['operation']['bairro'],
                'cidade' => $payload['operation']['cidade'],
                'estado' => $payload['operation']['estado'],
                'launch_date' => $this->monthToDate($payload['operation']['data_lancamento']),
                'sales_launch_date' => $this->monthToDate($payload['operation']['lancamento_vendas']),
                'construction_start_date' => $this->monthToDate($payload['operation']['inicio_obras']),
                'delivery_forecast_date' => $this->monthToDate($payload['operation']['previsao_entrega']),
            ];

            $remainingMonths = $this->calculateRemainingMonths(
                $sharedPayload['construction_start_date'],
                $sharedPayload['delivery_forecast_date'],
            );

            foreach ($payload['projects'] as $projectData) {
                $project = $this->upsertProposalProject($proposal, $projectData['id'] ?? null, [
                    ...$sharedPayload,
                    'name' => $projectData['name'],
                    'remaining_months' => $payload['operation']['prazo_remanescente'] ?: $remainingMonths,
                    'units_exchanged' => $projectData['units_exchanged'] ?? 0,
                    'units_paid' => $projectData['units_paid'] ?? 0,
                    'units_unpaid' => $projectData['units_unpaid'] ?? 0,
                    'units_stock' => $projectData['units_stock'] ?? 0,
                    'cost_incurred' => $this->normalizeDecimalOrNull($projectData['cost_incurred'] ?? null),
                    'cost_to_incur' => $this->normalizeDecimalOrNull($projectData['cost_to_incur'] ?? null),
                    'value_paid' => $this->normalizeDecimalOrNull($projectData['value_paid'] ?? null),
                    'value_unpaid' => $this->normalizeDecimalOrNull($projectData['value_unpaid'] ?? null),
                    'value_stock' => $this->normalizeDecimalOrNull($projectData['value_stock'] ?? null),
                    'value_received' => $this->normalizeDecimalOrNull($projectData['value_received'] ?? null),
                    'value_until_keys' => $this->normalizeDecimalOrNull($projectData['value_until_keys'] ?? null),
                    'value_post_keys' => $this->normalizeDecimalOrNull($projectData['value_post_keys'] ?? null),
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
     *     operation: array<string, mixed>,
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
                    'units_exchanged' => $validated['unidades_permutadas'][$index] ?? 0,
                    'units_paid' => $validated['unidades_quitadas'][$index] ?? 0,
                    'units_unpaid' => $validated['unidades_nao_quitadas'][$index] ?? 0,
                    'units_stock' => $validated['unidades_estoque'][$index] ?? 0,
                    'cost_incurred' => $validated['custo_incidido'][$index] ?? null,
                    'cost_to_incur' => $validated['custo_a_incorrer'][$index] ?? null,
                    'value_paid' => $validated['valor_quitadas'][$index] ?? null,
                    'value_unpaid' => $validated['valor_nao_quitadas'][$index] ?? null,
                    'value_stock' => $validated['valor_estoque'][$index] ?? null,
                    'value_received' => $validated['valor_ja_recebido'][$index] ?? null,
                    'value_until_keys' => $validated['valor_ate_chaves'][$index] ?? null,
                    'value_post_keys' => $validated['valor_chaves_pos'][$index] ?? null,
                ];
            })
            ->all();

        $unitTypes = collect($validated['tipo_total'] ?? [])
            ->values()
            ->map(function (mixed $totalUnits, int $index) use ($validated): array {
                return [
                    'total' => $totalUnits,
                    'bedrooms' => $validated['tipo_dormitorios'][$index] ?? null,
                    'parking_spaces' => $validated['tipo_vagas'][$index] ?? null,
                    'useful_area' => $validated['tipo_area'][$index] ?? null,
                    'average_price' => $validated['tipo_preco_medio'][$index] ?? null,
                ];
            })
            ->all();

        return [
            'operation' => [
                'nome' => $validated['nome'],
                'site' => $validated['site'] ?? null,
                'valor_solicitado' => $validated['valor_solicitado'],
                'valor_mercado_terreno' => $validated['valor_mercado_terreno'] ?? null,
                'area_terreno' => $validated['area_terreno'],
                'data_lancamento' => $validated['data_lancamento'],
                'lancamento_vendas' => $validated['lancamento_vendas'],
                'inicio_obras' => $validated['inicio_obras'],
                'previsao_entrega' => $validated['previsao_entrega'],
                'prazo_remanescente' => $validated['prazo_remanescente'] ?? null,
                'cep' => $validated['cep'],
                'logradouro' => $validated['logradouro'],
                'complemento' => $validated['complemento'] ?? null,
                'numero' => $validated['numero'],
                'bairro' => $validated['bairro'],
                'cidade' => $validated['cidade'],
                'estado' => $validated['estado'],
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
            $usefulArea = (float) ($unitType['useful_area'] ?? 0);

            $characteristic->unitTypes()->create([
                'order' => $typeIndex + 1,
                'total_units' => $unitType['total'],
                'bedrooms' => $unitType['bedrooms'] ?? null,
                'parking_spaces' => $unitType['parking_spaces'] ?? null,
                'useful_area' => $usefulArea,
                'average_price' => $averagePrice,
                'price_per_m2' => $usefulArea > 0 ? round($averagePrice / $usefulArea, 2) : 0,
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
