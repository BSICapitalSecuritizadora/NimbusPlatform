<?php

namespace App\Actions\Nimbus;

use App\Http\Requests\Nimbus\StoreSubmissionRequest;
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

    public function handle(StoreSubmissionRequest $request, PortalUser $portalUser): Submission
    {
        $shareholders = $this->parseShareholders((string) $request->input('shareholders', '[]'));

        return DB::transaction(function () use ($request, $portalUser, $shareholders): Submission {
            $cleanNetWorth = $this->normalizeMoneyInput((string) $request->net_worth);
            $cleanAnnualRevenue = $this->normalizeMoneyInput((string) $request->annual_revenue);

            $submission = Submission::query()->create([
                'nimbus_portal_user_id' => $portalUser->id,
                'reference_code' => $this->generateReferenceCode(),
                'submission_type' => 'REGISTRATION',
                'title' => Str::limit("Solicitação de cadastro - {$request->company_name}", 190, ''),
                'message' => null,
                'status' => Submission::STATUS_PENDING,
                'responsible_name' => $request->responsible_name,
                'company_cnpj' => $request->company_cnpj,
                'company_name' => $request->company_name,
                'main_activity' => $request->main_activity,
                'phone' => $request->phone,
                'website' => $request->website,
                'net_worth' => is_numeric($cleanNetWorth) ? $cleanNetWorth : 0,
                'annual_revenue' => is_numeric($cleanAnnualRevenue) ? $cleanAnnualRevenue : 0,
                'shareholder_data' => $shareholders,
                'registrant_name' => $request->registrant_name,
                'registrant_position' => $request->registrant_position,
                'registrant_rg' => $request->registrant_rg,
                'registrant_cpf' => $request->registrant_cpf,
                'is_us_person' => $request->boolean('is_us_person'),
                'is_pep' => $request->boolean('is_pep'),
                'created_ip' => $request->ip(),
                'created_user_agent' => Str::limit((string) $request->userAgent(), 255, ''),
                'submitted_at' => now(),
            ]);

            foreach ($shareholders as $shareholder) {
                if (! empty($shareholder['name'])) {
                    $submission->shareholders()->create([
                        'name' => $shareholder['name'],
                        'document_rg' => $shareholder['rg'] ?? null,
                        'document_cnpj' => $shareholder['cnpj'] ?? null,
                        'percentage' => (float) ($shareholder['percentage'] ?? 0),
                    ]);
                }
            }

            foreach (self::DOCUMENT_TYPE_MAP as $field => $documentType) {
                if ($request->hasFile($field)) {
                    $this->storeSubmissionFile->handle(
                        submission: $submission,
                        file: $request->file($field),
                        documentType: $documentType,
                        origin: 'USER',
                        visibleToUser: false,
                        uploadedByType: 'PORTAL_USER',
                        uploadedById: $portalUser->id,
                    );
                }
            }

            return $submission;
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function parseShareholders(string $payload): array
    {
        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function normalizeMoneyInput(string $value): string
    {
        return str_replace(',', '.', str_replace(['R$', '.', ' '], '', $value));
    }

    protected function generateReferenceCode(): string
    {
        return 'NMB-'.Str::upper((string) Str::ulid());
    }
}
