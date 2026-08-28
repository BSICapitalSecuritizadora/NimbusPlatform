<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

enum PuBaselineEvidenceDocumentType: string
{
    case SubscriptionBulletin = 'subscription_bulletin';
    case AcceptanceDocument = 'acceptance_document';
    case B3SettlementStatement = 'b3_settlement_statement';
    case RegistrarPosition = 'registrar_position';
    case CustodianPosition = 'custodian_position';
    case SettlingBankReceipt = 'settling_bank_receipt';
    case OfficialSettlementProof = 'official_settlement_proof';
    case FinalDistributionMap = 'final_distribution_map';
    case ClosingAnnouncement = 'closing_announcement';
    case OfficialPuMemory = 'official_pu_memory';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::SubscriptionBulletin => 'Boletim de Subscrição',
            self::AcceptanceDocument => 'Documento de aceitação',
            self::B3SettlementStatement => 'Extrato de liquidação B3',
            self::RegistrarPosition => 'Posição do escriturador',
            self::CustodianPosition => 'Posição do custodiante',
            self::SettlingBankReceipt => 'Comprovante do banco liquidante',
            self::OfficialSettlementProof => 'Comprovante oficial de liquidação',
            self::FinalDistributionMap => 'Mapa final de distribuição',
            self::ClosingAnnouncement => 'Anúncio de Encerramento',
            self::OfficialPuMemory => 'Memória oficial de PU',
            self::Other => 'Outro documento idôneo',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $type): array => [$type->value => $type->label()])
            ->all();
    }
}
