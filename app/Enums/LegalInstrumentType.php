<?php

namespace App\Enums;

/**
 * Natureza do instrumento jurídico da emissão (§23 do escopo).
 *
 * O domínio é genérico de propósito: CCB é apenas um caso. O que muda entre os
 * tipos é o conjunto de campos que a extração procura ({@see self::fieldKeys()}),
 * não a arquitetura — por isso não existe `CcbModel`.
 */
enum LegalInstrumentType: string
{
    case Ccb = 'ccb';
    case Cci = 'cci';
    case RealEstateFiduciaryAlienation = 'afi';
    case QuotaFiduciaryAlienation = 'afq';
    case FiduciaryAssignment = 'cessao_fiduciaria';
    case CreditAssignment = 'cessao_creditos';
    case EscrowAccountAgreement = 'contrato_conta';
    case Surety = 'fianca';
    case Aval = 'aval';
    case SecuritizationTerm = 'termo_securitizacao';
    case DistributionAgreement = 'contrato_distribuicao';
    case Other = 'outro';

    public function label(): string
    {
        return match ($this) {
            self::Ccb => 'CCB — Cédula de Crédito Bancário',
            self::Cci => 'CCI — Cédula de Crédito Imobiliário',
            self::RealEstateFiduciaryAlienation => 'Alienação Fiduciária de Imóvel',
            self::QuotaFiduciaryAlienation => 'Alienação Fiduciária de Quotas',
            self::FiduciaryAssignment => 'Cessão Fiduciária',
            self::CreditAssignment => 'Contrato de Cessão de Créditos',
            self::EscrowAccountAgreement => 'Contrato de Conta Vinculada',
            self::Surety => 'Fiança',
            self::Aval => 'Aval',
            self::SecuritizationTerm => 'Termo de Securitização',
            self::DistributionAgreement => 'Contrato de Distribuição',
            self::Other => 'Outro instrumento',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Ccb => 'CCB',
            self::Cci => 'CCI',
            self::RealEstateFiduciaryAlienation => 'AFI',
            self::QuotaFiduciaryAlienation => 'AFQ',
            default => $this->label(),
        };
    }

    /**
     * Campos que a extração procura neste tipo de instrumento.
     *
     * Todos os tipos partilham o bloco de identificação e o de garantias; o que
     * varia é o miolo. Um tipo novo entra declarando os seus campos aqui, sem
     * tocar no motor de consolidação.
     *
     * @return array<int, LegalInstrumentFieldKey>
     */
    public function fieldKeys(): array
    {
        $common = [
            LegalInstrumentFieldKey::Number,
            LegalInstrumentFieldKey::IssueDate,
            LegalInstrumentFieldKey::IssuePlace,
            LegalInstrumentFieldKey::Issuer,
            LegalInstrumentFieldKey::IssuerTaxId,
            LegalInstrumentFieldKey::Creditor,
            LegalInstrumentFieldKey::Assignee,
            LegalInstrumentFieldKey::Guarantors,
            LegalInstrumentFieldKey::MinimumCoverage,
        ];

        return match ($this) {
            self::Ccb, self::Cci => array_merge($common, [
                LegalInstrumentFieldKey::OriginalAmount,
                LegalInstrumentFieldKey::PrincipalAmount,
                LegalInstrumentFieldKey::Indexer,
                LegalInstrumentFieldKey::Remuneration,
                LegalInstrumentFieldKey::InterestRate,
                LegalInstrumentFieldKey::Spread,
                LegalInstrumentFieldKey::DefaultInterest,
                LegalInstrumentFieldKey::Penalty,
                LegalInstrumentFieldKey::PaymentSchedule,
                LegalInstrumentFieldKey::Amortization,
                LegalInstrumentFieldKey::GracePeriod,
                LegalInstrumentFieldKey::MaturityDate,
                LegalInstrumentFieldKey::AffirmativeCovenants,
                LegalInstrumentFieldKey::NegativeCovenants,
                LegalInstrumentFieldKey::AccelerationEvents,
                LegalInstrumentFieldKey::InformationObligations,
                LegalInstrumentFieldKey::Avalists,
            ]),

            self::RealEstateFiduciaryAlienation => array_merge($common, [
                LegalInstrumentFieldKey::PropertyRegistration,
                LegalInstrumentFieldKey::RegistryOffice,
                LegalInstrumentFieldKey::PropertyDescription,
                LegalInstrumentFieldKey::PropertyValue,
                LegalInstrumentFieldKey::FiduciaryGrantor,
                LegalInstrumentFieldKey::FiduciaryCreditor,
            ]),

            self::QuotaFiduciaryAlienation => array_merge($common, [
                LegalInstrumentFieldKey::Company,
                LegalInstrumentFieldKey::CompanyTaxId,
                LegalInstrumentFieldKey::QuotaQuantity,
                LegalInstrumentFieldKey::QuotaPercentage,
                LegalInstrumentFieldKey::FiduciaryGrantor,
                LegalInstrumentFieldKey::FiduciaryCreditor,
            ]),

            self::FiduciaryAssignment, self::CreditAssignment => array_merge($common, [
                LegalInstrumentFieldKey::Assignor,
                LegalInstrumentFieldKey::AssignedCredits,
                LegalInstrumentFieldKey::AssignedContracts,
                LegalInstrumentFieldKey::AssignedPercentage,
                LegalInstrumentFieldKey::EligibilityRules,
            ]),

            self::EscrowAccountAgreement => array_merge($common, [
                LegalInstrumentFieldKey::Bank,
                LegalInstrumentFieldKey::AccountNumber,
                LegalInstrumentFieldKey::AccountRules,
            ]),

            default => array_merge($common, [
                LegalInstrumentFieldKey::OriginalAmount,
                LegalInstrumentFieldKey::MaturityDate,
            ]),
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $type) {
            $options[$type->value] = $type->label();
        }

        return $options;
    }
}
