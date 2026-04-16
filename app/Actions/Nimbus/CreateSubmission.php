<?php

namespace App\Actions\Nimbus;

use App\DTOs\Nimbus\StoreSubmissionDTO;
use App\DTOs\Nimbus\StoreSubmissionFileDTO;
use App\Models\Nimbus\PortalUser;
use App\Models\Nimbus\Submission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateSubmission
{
    /**
     * @var array<string, string>
     */
    private const DOCUMENT_TYPE_MAP = [
        'ultimo_balanco' => 'BALANCE_SHEET',
        'dre' => 'DRE',
        'politicas' => 'POLICIES',
        'cartao_cnpj' => 'CNPJ_CARD',
        'procuracao' => 'POWER_OF_ATTORNEY',
        'ata' => 'MINUTES',
        'contrato_social' => 'ARTICLES_OF_INCORPORATION',
        'estatuto' => 'BYLAWS',
    ];

    public function __construct(
        protected StoreSubmissionFile $storeSubmissionFile,
    ) {}

    public function handle(StoreSubmissionDTO $dto, PortalUser $portalUser): Submission
    {
        return DB::transaction(function () use ($dto, $portalUser): Submission {
            $submission = Submission::query()->create([
                'nimbus_portal_user_id' => $portalUser->id,
                'reference_code' => $this->generateReferenceCode(),
                'submission_type' => 'REGISTRATION',
                'title' => Str::limit("Solicitação de cadastro - {$dto->companyName}", 190, ''),
                'message' => null,
                'status' => Submission::STATUS_PENDING,
                'responsible_name' => $dto->responsibleName,
                'company_cnpj' => $dto->companyCnpj,
                'company_name' => $dto->companyName,
                'main_activity' => $dto->mainActivity,
                'phone' => $dto->phone,
                'website' => $dto->website,
                'net_worth' => $dto->netWorth,
                'annual_revenue' => $dto->annualRevenue,
                'shareholder_data' => $dto->shareholderData(),
                'registrant_name' => $dto->registrantName,
                'registrant_position' => $dto->registrantPosition,
                'registrant_rg' => $dto->registrantRg,
                'registrant_cpf' => $dto->registrantCpf,
                'is_us_person' => $dto->isUsPerson,
                'is_pep' => $dto->isPep,
                'created_ip' => $dto->ip,
                'created_user_agent' => Str::limit((string) $dto->userAgent, 255, ''),
                'submitted_at' => now(),
            ]);

            foreach ($dto->shareholders as $shareholder) {
                if ($shareholder->name !== '') {
                    $submission->shareholders()->create([
                        'name' => $shareholder->name,
                        'document_rg' => $shareholder->rg,
                        'document_cnpj' => $shareholder->cnpj,
                        'percentage' => $shareholder->percentage,
                    ]);
                }
            }

            foreach ($dto->documentFiles as $field => $documentFile) {
                $this->storeSubmissionFile->handle($submission, new StoreSubmissionFileDTO(
                    file: $documentFile,
                    documentType: self::DOCUMENT_TYPE_MAP[$field],
                    origin: 'USER',
                    visibleToUser: false,
                    uploadedByType: 'PORTAL_USER',
                    uploadedById: $portalUser->id,
                ));
            }

            return $submission;
        });
    }

    protected function generateReferenceCode(): string
    {
        return 'NMB-'.Str::upper((string) Str::ulid());
    }
}
