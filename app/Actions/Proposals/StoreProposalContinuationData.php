<?php

namespace App\Actions\Proposals;

use App\DTOs\Proposals\ProposalContinuationCharacteristicsDTO;
use App\DTOs\Proposals\ProposalContinuationProjectDTO;
use App\DTOs\Proposals\ProposalContinuationUnitTypeDTO;
use App\DTOs\Proposals\StoreProposalContinuationDataDTO;
use App\DTOs\Proposals\UpdateProposalStatusDTO;
use App\Enums\ProposalStatus;
use App\Models\ProjectCharacteristic;
use App\Models\Proposal;
use App\Models\ProposalProject;
use App\Services\DocumentStorageService;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StoreProposalContinuationData
{
    public function __construct(
        protected UpdateProposalStatus $updateProposalStatus,
        protected DocumentStorageService $documentStorageService,
    ) {}

    /**
     * @param  array<int, UploadedFile>  $files
     */
    public function handle(Proposal $proposal, StoreProposalContinuationDataDTO $dto, array $files = []): void
    {
        DB::transaction(function () use ($proposal, $dto, $files): void {
            $sharedPayload = [
                'development_name' => $dto->overview->developmentName,
                'website_url' => $dto->overview->websiteUrl,
                'requested_amount' => $dto->overview->requestedAmount,
                'land_market_value' => $dto->overview->landMarketValue,
                'land_area' => $dto->overview->landArea,
                'zip_code' => $dto->overview->zipCode,
                'street' => $dto->overview->street,
                'address_number' => $dto->overview->addressNumber,
                'address_complement' => $dto->overview->addressComplement,
                'neighborhood' => $dto->overview->neighborhood,
                'city' => $dto->overview->city,
                'state' => $dto->overview->state,
                'launch_date' => $this->monthToDate($dto->overview->launchDate),
                'sales_launch_date' => $this->monthToDate($dto->overview->salesLaunchDate),
                'construction_start_date' => $this->monthToDate($dto->overview->constructionStartDate),
                'delivery_forecast_date' => $this->monthToDate($dto->overview->deliveryForecastDate),
            ];

            $remainingMonths = $this->calculateRemainingMonths(
                $sharedPayload['construction_start_date'],
                $sharedPayload['delivery_forecast_date'],
            );

            foreach ($dto->projects as $projectData) {
                $project = $this->upsertProposalProject($proposal, $projectData, [
                    ...$sharedPayload,
                    'name' => $projectData->name,
                    'remaining_months' => $dto->overview->remainingMonths ?? $remainingMonths,
                    'exchanged_units' => $projectData->exchangedUnits,
                    'paid_units' => $projectData->paidUnits,
                    'unpaid_units' => $projectData->unpaidUnits,
                    'stock_units' => $projectData->stockUnits,
                    'incurred_cost' => $projectData->incurredCost,
                    'cost_to_incur' => $projectData->costToIncur,
                    'paid_sales_value' => $projectData->paidSalesValue,
                    'unpaid_sales_value' => $projectData->unpaidSalesValue,
                    'stock_sales_value' => $projectData->stockSalesValue,
                    'received_value' => $projectData->receivedValue,
                    'value_until_keys' => $projectData->valueUntilKeys,
                    'value_after_keys' => $projectData->valueAfterKeys,
                ]);

                $this->syncProjectCharacteristics($project, $dto->characteristics, $dto->unitTypes);
            }

            if ($files !== []) {
                $this->storeUploadedFiles($proposal, $files);
            }

            $proposal->forceFill([
                'completed_at' => now(),
            ])->save();

            if (in_array($proposal->status, [
                ProposalStatus::AwaitingCompletion->value,
                ProposalStatus::AwaitingInformation->value,
            ], true)) {
                $this->updateProposalStatus->handle(
                    $proposal,
                    UpdateProposalStatusDTO::fromArray([
                        'status' => ProposalStatus::InReview->value,
                        'note' => 'Informações complementares enviadas pelo proponente.',
                        'authorize' => false,
                    ]),
                );
            }
        });
    }

    protected function monthToDate(string $value): string
    {
        return Carbon::createFromFormat('Y-m', $value)->startOfMonth()->toDateString();
    }

    protected function calculateRemainingMonths(string $startDate, string $endDate): int
    {
        return Carbon::parse($startDate)->diffInMonths(Carbon::parse($endDate));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function upsertProposalProject(
        Proposal $proposal,
        ProposalContinuationProjectDTO $projectData,
        array $attributes,
    ): ProposalProject {
        if ($projectData->id === null) {
            return $proposal->projects()->create($attributes);
        }

        $project = $proposal->projects()->whereKey($projectData->id)->first();

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
     * @param  array<int, ProposalContinuationUnitTypeDTO>  $unitTypes
     */
    protected function syncProjectCharacteristics(
        ProposalProject $project,
        ProposalContinuationCharacteristicsDTO $characteristics,
        array $unitTypes,
    ): void {
        /** @var ProjectCharacteristic $characteristic */
        $characteristic = $project->characteristics()->firstOrNew();
        $characteristic->fill([
            'blocks' => $characteristics->blocks,
            'floors' => $characteristics->floors,
            'typical_floors' => $characteristics->typicalFloors,
            'units_per_floor' => $characteristics->unitsPerFloor,
            'total_units' => $characteristics->totalUnits
                ?? ($characteristics->blocks * $characteristics->typicalFloors * $characteristics->unitsPerFloor),
        ]);
        $characteristic->save();

        $characteristic->unitTypes()->delete();

        foreach ($unitTypes as $typeIndex => $unitType) {
            $characteristic->unitTypes()->create([
                'order' => $typeIndex + 1,
                'total_units' => $unitType->totalUnits,
                'bedrooms' => $unitType->bedrooms,
                'parking_spaces' => $unitType->parkingSpaces,
                'usable_area' => $unitType->usableArea,
                'average_price' => $unitType->averagePrice,
                'price_per_square_meter' => $unitType->usableArea > 0
                    ? round($unitType->averagePrice / $unitType->usableArea, 2)
                    : 0,
            ]);
        }
    }

    private const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    private const MAX_FILE_SIZE_BYTES = 20 * 1024 * 1024; // 20 MB

    /**
     * @param  array<int, UploadedFile>  $files
     */
    protected function storeUploadedFiles(Proposal $proposal, array $files): void
    {
        foreach ($files as $file) {
            if (! in_array($file->getMimeType(), self::ALLOWED_MIME_TYPES, true)) {
                throw ValidationException::withMessages([
                    'arquivos' => 'Tipo de arquivo não permitido. Envie PDF, imagens, planilhas ou documentos Word.',
                ]);
            }

            if ($file->getSize() > self::MAX_FILE_SIZE_BYTES) {
                throw ValidationException::withMessages([
                    'arquivos' => 'O arquivo ultrapassa o tamanho máximo de 20 MB.',
                ]);
            }
        }

        foreach ($files as $file) {
            $storedFile = $this->documentStorageService->storePrivateFile(
                $file,
                "proposal-files/{$proposal->id}",
            );

            $proposal->files()->create([
                'disk' => $storedFile['disk'],
                'file_path' => $storedFile['path'],
                'file_name' => $storedFile['stored_name'],
                'original_name' => $storedFile['original_name'],
                'mime_type' => $storedFile['mime_type'],
                'file_size' => $storedFile['size_bytes'],
            ]);
        }
    }
}
