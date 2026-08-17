<?php

namespace App\Enums;

/**
 * Tipos de garantia usados em operações de CRI, CRA e CR (§10 do escopo).
 *
 * O tipo não carrega campos próprios: ele resolve para uma
 * {@see GuaranteeCategory}, e é a categoria que define a identificação
 * operacional e a origem do valor atual. Tipos novos entram sem tocar no
 * motor de cálculo desde que se encaixem numa categoria existente.
 *
 * `null` é um valor legítimo para o tipo de uma garantia: significa "pendente
 * de classificação", estado das garantias cadastradas antes deste módulo. Use
 * {@see self::labelFor()} em vez de acessar `->label()` sem checagem.
 */
enum GuaranteeType: string
{
    case RealEstateFiduciaryAlienation = 'af_imovel';
    case QuotaFiduciaryAlienation = 'af_quotas';
    case ReceivablesFiduciaryAssignment = 'cf_recebiveis';
    case CreditRightsFiduciaryAssignment = 'cf_direitos_creditorios';
    case FiduciaryAssignmentPromise = 'promessa_cessao_fiduciaria';
    case Mortgage = 'hipoteca';
    case Pledge = 'penhor';
    case Aval = 'aval';
    case Surety = 'fianca';
    case ReserveFund = 'fundo_reserva';
    case InterestFund = 'fundo_juros';
    case WorksFund = 'fundo_obras';
    case ReserveAccount = 'conta_reserva';
    case EscrowAccount = 'conta_vinculada';
    case Receivables = 'recebiveis';
    case Inventory = 'estoque';
    case Units = 'unidades';
    case BankGuaranteeLetter = 'carta_fianca';
    case SuretyInsurance = 'seguro_garantia';
    case FinancialInvestment = 'aplicacao_financeira';
    case Other = 'outra';

    public function label(): string
    {
        return match ($this) {
            self::RealEstateFiduciaryAlienation => 'Alienação Fiduciária de Imóvel',
            self::QuotaFiduciaryAlienation => 'Alienação Fiduciária de Quotas',
            self::ReceivablesFiduciaryAssignment => 'Cessão Fiduciária de Recebíveis',
            self::CreditRightsFiduciaryAssignment => 'Cessão Fiduciária de Direitos Creditórios',
            self::FiduciaryAssignmentPromise => 'Promessa de Cessão Fiduciária',
            self::Mortgage => 'Hipoteca',
            self::Pledge => 'Penhor',
            self::Aval => 'Aval',
            self::Surety => 'Fiança',
            self::ReserveFund => 'Fundo de Reserva',
            self::InterestFund => 'Fundo de Juros',
            self::WorksFund => 'Fundo de Obras',
            self::ReserveAccount => 'Conta Reserva',
            self::EscrowAccount => 'Conta Vinculada',
            self::Receivables => 'Recebíveis',
            self::Inventory => 'Estoque',
            self::Units => 'Unidades',
            self::BankGuaranteeLetter => 'Carta Fiança',
            self::SuretyInsurance => 'Seguro Garantia',
            self::FinancialInvestment => 'Aplicação Financeira',
            self::Other => 'Outras garantias',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::RealEstateFiduciaryAlienation => 'AF Imóvel',
            self::QuotaFiduciaryAlienation => 'AF Quotas',
            self::ReceivablesFiduciaryAssignment => 'CF Recebíveis',
            self::CreditRightsFiduciaryAssignment => 'CF Direitos Creditórios',
            self::FiduciaryAssignmentPromise => 'Promessa de CF',
            default => $this->label(),
        };
    }

    public function category(): GuaranteeCategory
    {
        return match ($this) {
            self::RealEstateFiduciaryAlienation,
            self::Mortgage => GuaranteeCategory::RealEstate,

            self::QuotaFiduciaryAlienation => GuaranteeCategory::Quotas,

            self::ReceivablesFiduciaryAssignment,
            self::CreditRightsFiduciaryAssignment,
            self::FiduciaryAssignmentPromise,
            self::Receivables => GuaranteeCategory::Receivables,

            self::ReserveFund,
            self::InterestFund,
            self::WorksFund,
            self::ReserveAccount,
            self::EscrowAccount,
            self::FinancialInvestment => GuaranteeCategory::FundAccount,

            self::Inventory,
            self::Units => GuaranteeCategory::Inventory,

            self::Aval,
            self::Surety => GuaranteeCategory::Personal,

            self::BankGuaranteeLetter,
            self::SuretyInsurance => GuaranteeCategory::Insurance,

            self::Pledge,
            self::Other => GuaranteeCategory::Other,
        };
    }

    /**
     * Fonte que o motor tenta primeiro para o valor atual (§15 do escopo).
     *
     * AF de imóvel resolve por avaliação, não por dado operacional: o Nimbus
     * não tem como recalcular o valor de um imóvel sozinho, e inventar um
     * seria pior do que pedir o laudo.
     */
    public function defaultValueSource(): GuaranteeValueSource
    {
        return match ($this->category()) {
            GuaranteeCategory::Receivables => GuaranteeValueSource::ReceivablesPortfolio,
            GuaranteeCategory::Inventory => GuaranteeValueSource::SalesBoard,
            GuaranteeCategory::FundAccount => GuaranteeValueSource::FundBalance,
            GuaranteeCategory::RealEstate => GuaranteeValueSource::Valuation,
            GuaranteeCategory::Quotas => GuaranteeValueSource::Manual,
            GuaranteeCategory::Personal,
            GuaranteeCategory::Insurance => GuaranteeValueSource::Manual,
            GuaranteeCategory::Other => GuaranteeValueSource::Manual,
        };
    }

    /**
     * Garantias pessoais e securitárias respondem por um limite, não por um
     * valor de mercado que varie mês a mês.
     */
    public function hasMonetaryPosition(): bool
    {
        return $this->category() !== GuaranteeCategory::Personal;
    }

    public static function labelFor(?self $type): string
    {
        return $type?->label() ?? 'Pendente de classificação';
    }

    /**
     * Opções agrupadas por categoria, para selects do painel.
     *
     * @return array<string, array<string, string>>
     */
    public static function groupedOptions(): array
    {
        $options = [];

        foreach (self::cases() as $type) {
            $options[$type->category()->label()][$type->value] = $type->label();
        }

        return $options;
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
