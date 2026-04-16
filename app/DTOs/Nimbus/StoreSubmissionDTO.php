<?php

declare(strict_types=1);

namespace App\DTOs\Nimbus;

use App\DTOs\BaseDTO;
use Illuminate\Http\UploadedFile;

readonly class StoreSubmissionDTO extends BaseDTO
{
    /**
     * @param  array<int, SubmissionShareholderDTO>  $shareholders
     * @param  array<string, UploadedFile>  $documentFiles
     */
    public function __construct(
        public string $responsibleName,
        public string $companyCnpj,
        public string $companyName,
        public ?string $mainActivity,
        public string $phone,
        public ?string $website,
        public float $netWorth,
        public float $annualRevenue,
        public string $registrantName,
        public ?string $registrantPosition,
        public ?string $registrantRg,
        public string $registrantCpf,
        public bool $isUsPerson,
        public bool $isPep,
        public array $shareholders,
        public array $documentFiles,
        public string $ip,
        public ?string $userAgent,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            responsibleName: trim((string) ($data['responsible_name'] ?? '')),
            companyCnpj: trim((string) ($data['company_cnpj'] ?? '')),
            companyName: trim((string) ($data['company_name'] ?? '')),
            mainActivity: self::nullableString($data['main_activity'] ?? null),
            phone: trim((string) ($data['phone'] ?? '')),
            website: self::nullableString($data['website'] ?? null),
            netWorth: self::money($data['net_worth'] ?? 0),
            annualRevenue: self::money($data['annual_revenue'] ?? 0),
            registrantName: trim((string) ($data['registrant_name'] ?? '')),
            registrantPosition: self::nullableString($data['registrant_position'] ?? null),
            registrantRg: self::nullableString($data['registrant_rg'] ?? null),
            registrantCpf: trim((string) ($data['registrant_cpf'] ?? '')),
            isUsPerson: (bool) ($data['is_us_person'] ?? false),
            isPep: (bool) ($data['is_pep'] ?? false),
            shareholders: self::shareholders($data['shareholders'] ?? null),
            documentFiles: self::documentFiles($data),
            ip: trim((string) ($data['ip'] ?? '')),
            userAgent: self::nullableString($data['user_agent'] ?? null),
        );
    }

    /**
     * @return array<int, array{name:string,rg:?string,cnpj:?string,percentage:float}>
     */
    public function shareholderData(): array
    {
        return array_map(
            static fn (SubmissionShareholderDTO $shareholder): array => $shareholder->toArray(),
            $this->shareholders,
        );
    }

    /**
     * @return array<int, SubmissionShareholderDTO>
     */
    protected static function shareholders(mixed $value): array
    {
        if (is_string($value) && trim($value) !== '') {
            $value = json_decode($value, true);
        }

        if (! is_array($value)) {
            return [];
        }

        return array_map(
            static fn (array $shareholder): SubmissionShareholderDTO => SubmissionShareholderDTO::fromArray($shareholder),
            array_values(array_filter($value, is_array(...))),
        );
    }

    /**
     * @return array<string, UploadedFile>
     */
    protected static function documentFiles(array $data): array
    {
        $files = [];

        foreach ([
            'ultimo_balanco',
            'dre',
            'politicas',
            'cartao_cnpj',
            'procuracao',
            'ata',
            'contrato_social',
            'estatuto',
        ] as $field) {
            $file = $data[$field] ?? null;

            if ($file instanceof UploadedFile) {
                $files[$field] = $file;
            }
        }

        return $files;
    }

    protected static function money(mixed $value): float
    {
        $normalized = str_replace(',', '.', str_replace(['R$', '.', ' '], '', (string) $value));

        return (float) $normalized;
    }
}
