<?php

namespace App\Enums;

/**
 * Vocabulário controlado dos campos consolidáveis de um instrumento (§6 e §24).
 *
 * Existe para que "valor da CCB" no documento original e "valor" no 1º
 * aditamento sejam reconhecidos como o mesmo campo — sem isso não há como
 * dizer que o aditamento *alterou* algo em vez de acrescentar um dado novo.
 *
 * O tipo do valor ({@see self::valueType()}) decide em qual coluna o número ou
 * a data são gravados, o que torna a comparação entre versões exata em vez de
 * textual.
 */
enum LegalInstrumentFieldKey: string
{
    // Identificação
    case Number = 'number';
    case IssueDate = 'issue_date';
    case IssuePlace = 'issue_place';
    case Issuer = 'issuer';
    case IssuerTaxId = 'issuer_tax_id';
    case Creditor = 'creditor';
    case Assignee = 'assignee';
    case Guarantors = 'guarantors';
    case Avalists = 'avalists';

    // Financeiro
    case OriginalAmount = 'original_amount';
    case PrincipalAmount = 'principal_amount';
    case Indexer = 'indexer';
    case Remuneration = 'remuneration';
    case InterestRate = 'interest_rate';
    case Spread = 'spread';
    case DefaultInterest = 'default_interest';
    case Penalty = 'penalty';
    case PaymentSchedule = 'payment_schedule';
    case Amortization = 'amortization';
    case GracePeriod = 'grace_period';
    case MaturityDate = 'maturity_date';

    // Obrigações e covenants
    case AffirmativeCovenants = 'affirmative_covenants';
    case NegativeCovenants = 'negative_covenants';
    case AccelerationEvents = 'acceleration_events';
    case InformationObligations = 'information_obligations';

    // Cobertura
    case MinimumCoverage = 'minimum_coverage';

    // Imóvel (AFI)
    case PropertyRegistration = 'property_registration';
    case RegistryOffice = 'registry_office';
    case PropertyDescription = 'property_description';
    case PropertyValue = 'property_value';
    case FiduciaryGrantor = 'fiduciary_grantor';
    case FiduciaryCreditor = 'fiduciary_creditor';

    // Quotas (AFQ)
    case Company = 'company';
    case CompanyTaxId = 'company_tax_id';
    case QuotaQuantity = 'quota_quantity';
    case QuotaPercentage = 'quota_percentage';

    // Cessão
    case Assignor = 'assignor';
    case AssignedCredits = 'assigned_credits';
    case AssignedContracts = 'assigned_contracts';
    case AssignedPercentage = 'assigned_percentage';
    case EligibilityRules = 'eligibility_rules';

    // Conta vinculada
    case Bank = 'bank';
    case Agency = 'agency';
    case AccountNumber = 'account_number';
    case AccountRules = 'account_rules';

    // Garantia
    case GuaranteeValue = 'guarantee_value';

    public function label(): string
    {
        return match ($this) {
            self::Number => 'Número',
            self::IssueDate => 'Data de emissão',
            self::IssuePlace => 'Local de emissão',
            self::Issuer => 'Emitente',
            self::IssuerTaxId => 'CNPJ/CPF do emitente',
            self::Creditor => 'Credor',
            self::Assignee => 'Cessionário',
            self::Guarantors => 'Garantidores',
            self::Avalists => 'Avalistas',
            self::OriginalAmount => 'Valor original',
            self::PrincipalAmount => 'Valor principal',
            self::Indexer => 'Indexador',
            self::Remuneration => 'Remuneração',
            self::InterestRate => 'Taxa de juros',
            self::Spread => 'Spread',
            self::DefaultInterest => 'Juros de mora',
            self::Penalty => 'Multa',
            self::PaymentSchedule => 'Cronograma de pagamento',
            self::Amortization => 'Amortização',
            self::GracePeriod => 'Carência',
            self::MaturityDate => 'Vencimento final',
            self::AffirmativeCovenants => 'Obrigações de fazer',
            self::NegativeCovenants => 'Obrigações de não fazer',
            self::AccelerationEvents => 'Eventos de vencimento antecipado',
            self::InformationObligations => 'Obrigações de informação',
            self::MinimumCoverage => 'Cobertura mínima',
            self::PropertyRegistration => 'Matrícula',
            self::RegistryOffice => 'Cartório',
            self::PropertyDescription => 'Descrição do imóvel',
            self::PropertyValue => 'Valor do imóvel',
            self::FiduciaryGrantor => 'Fiduciante',
            self::FiduciaryCreditor => 'Credor fiduciário',
            self::Company => 'Sociedade',
            self::CompanyTaxId => 'CNPJ da sociedade',
            self::QuotaQuantity => 'Quantidade de quotas',
            self::QuotaPercentage => 'Percentual de quotas',
            self::Assignor => 'Cedente',
            self::AssignedCredits => 'Créditos cedidos',
            self::AssignedContracts => 'Contratos cedidos',
            self::AssignedPercentage => 'Percentual cedido',
            self::EligibilityRules => 'Regras de elegibilidade',
            self::Bank => 'Banco',
            self::Agency => 'Agência',
            self::AccountNumber => 'Conta',
            self::AccountRules => 'Regras da conta',
            self::GuaranteeValue => 'Valor da garantia',
        };
    }

    public function group(): string
    {
        return match ($this) {
            self::Number, self::IssueDate, self::IssuePlace, self::Issuer,
            self::IssuerTaxId, self::Creditor, self::Assignee,
            self::Guarantors, self::Avalists => 'Identificação',

            self::OriginalAmount, self::PrincipalAmount, self::Indexer,
            self::Remuneration, self::InterestRate, self::Spread,
            self::DefaultInterest, self::Penalty, self::PaymentSchedule,
            self::Amortization, self::GracePeriod, self::MaturityDate => 'Financeiro',

            self::AffirmativeCovenants, self::NegativeCovenants,
            self::AccelerationEvents, self::InformationObligations => 'Obrigações',

            self::MinimumCoverage => 'Cobertura',

            default => 'Garantia',
        };
    }

    public function valueType(): LegalInstrumentFieldValueType
    {
        return match ($this) {
            self::OriginalAmount, self::PrincipalAmount, self::PropertyValue,
            self::GuaranteeValue => LegalInstrumentFieldValueType::Money,
            self::InterestRate, self::Spread, self::MinimumCoverage,
            self::QuotaPercentage, self::AssignedPercentage => LegalInstrumentFieldValueType::Percentage,
            self::QuotaQuantity => LegalInstrumentFieldValueType::Number,
            self::IssueDate, self::MaturityDate => LegalInstrumentFieldValueType::Date,
            default => LegalInstrumentFieldValueType::Text,
        };
    }

    /**
     * Campos cuja alteração é material e exige confirmação humana (§20).
     *
     * Na prática todos os campos passam por revisão; esta lista marca os que a
     * interface destaca como críticos.
     */
    public function isMaterial(): bool
    {
        return match ($this) {
            self::OriginalAmount, self::PrincipalAmount, self::MaturityDate,
            self::MinimumCoverage, self::PropertyRegistration, self::QuotaPercentage,
            self::AssignedPercentage, self::Issuer, self::Creditor,
            self::Guarantors, self::Avalists, self::AccountNumber,
            self::GuaranteeValue => true,
            default => false,
        };
    }

    /**
     * @return array<string, array<string, string>>
     */
    public static function groupedOptions(): array
    {
        $options = [];

        foreach (self::cases() as $key) {
            $options[$key->group()][$key->value] = $key->label();
        }

        return $options;
    }
}
