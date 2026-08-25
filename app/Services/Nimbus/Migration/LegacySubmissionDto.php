<?php

namespace App\Services\Nimbus\Migration;

readonly class LegacySubmissionDto
{
    public function __construct(
        public int|string $id,
        public int|string $portalUserId,
        public string $referenceCode,
        public string $submissionType,
        public string $title,
        public ?string $message,
        public ?string $responsibleName,
        public ?string $companyCnpj,
        public ?string $companyName,
        public ?string $mainActivity,
        public ?string $phone,
        public ?string $website,
        public ?string $netWorth,
        public ?string $annualRevenue,
        public bool $isUsPerson,
        public bool $isPep,
        public ?string $shareholderData,
        public ?string $registrantName,
        public ?string $registrantPosition,
        public ?string $registrantRg,
        public ?string $registrantCpf,
        public string $status,
        public ?string $createdIp,
        public ?string $createdUserAgent,
        public string $submittedAt,
        public ?string $statusUpdatedAt,
        public ?string $statusUpdatedBy,
        public string $createdAt,
        public ?string $updatedAt,
    ) {}

    public static function fromArray(array $row): self
    {
        return new self(
            id: $row['id'],
            portalUserId: $row['portal_user_id'],
            referenceCode: $row['reference_code'],
            submissionType: $row['submission_type'] ?? 'REGISTRATION',
            title: $row['title'],
            message: $row['message'] ?? null,
            responsibleName: $row['responsible_name'] ?? null,
            companyCnpj: $row['company_cnpj'] ?? null,
            companyName: $row['company_name'] ?? null,
            mainActivity: $row['main_activity'] ?? null,
            phone: $row['phone'] ?? null,
            website: $row['website'] ?? null,
            netWorth: isset($row['net_worth']) ? (string) $row['net_worth'] : null,
            annualRevenue: isset($row['annual_revenue']) ? (string) $row['annual_revenue'] : null,
            isUsPerson: (bool) ($row['is_us_person'] ?? false),
            isPep: (bool) ($row['is_pep'] ?? false),
            shareholderData: $row['shareholder_data'] ?? null,
            registrantName: $row['registrant_name'] ?? null,
            registrantPosition: $row['registrant_position'] ?? null,
            registrantRg: $row['registrant_rg'] ?? null,
            registrantCpf: $row['registrant_cpf'] ?? null,
            status: $row['status'] ?? 'PENDING',
            createdIp: $row['created_ip'] ?? null,
            createdUserAgent: $row['created_user_agent'] ?? null,
            submittedAt: $row['submitted_at'] ?? $row['created_at'] ?? now()->toDateTimeString(),
            statusUpdatedAt: $row['status_updated_at'] ?? null,
            statusUpdatedBy: isset($row['status_updated_by']) ? (string) $row['status_updated_by'] : null,
            createdAt: $row['created_at'] ?? now()->toDateTimeString(),
            updatedAt: $row['updated_at'] ?? null,
        );
    }
}
