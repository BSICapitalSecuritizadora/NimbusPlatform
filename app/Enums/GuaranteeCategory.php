<?php

namespace App\Enums;

/**
 * Famílias de garantia que compartilham a mesma identificação operacional e a
 * mesma origem de valor. É a categoria — não o tipo — que decide quais campos
 * de identificação existem e de onde o Nimbus tenta ler o valor atual.
 */
enum GuaranteeCategory: string
{
    case RealEstate = 'real_estate';
    case Quotas = 'quotas';
    case Receivables = 'receivables';
    case FundAccount = 'fund_account';
    case Inventory = 'inventory';
    case Personal = 'personal';
    case Insurance = 'insurance';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::RealEstate => 'Imóvel',
            self::Quotas => 'Quotas / Participação societária',
            self::Receivables => 'Recebíveis',
            self::FundAccount => 'Fundos e contas vinculadas',
            self::Inventory => 'Estoque e unidades',
            self::Personal => 'Garantia pessoal',
            self::Insurance => 'Seguro / Carta fiança',
            self::Other => 'Outras',
        };
    }

    /**
     * Campos de identificação previstos para a categoria (§11 do escopo).
     *
     * @return array<string, string>
     */
    public function identificationFields(): array
    {
        return match ($this) {
            self::RealEstate => [
                'registration_number' => 'Matrícula',
                'registry_office' => 'Cartório',
                'city' => 'Cidade',
                'state' => 'Estado',
                'owner' => 'Proprietário',
                'construction' => 'Empreendimento',
                'unit' => 'Unidade',
                'area' => 'Área',
            ],
            self::Quotas => [
                'company' => 'Empresa / SPE',
                'tax_id' => 'CNPJ',
                'quota_quantity' => 'Quantidade de quotas',
                'pledged_percentage' => 'Percentual alienado',
                'nominal_value' => 'Valor nominal',
                'equity_value' => 'Valor patrimonial',
                'grantor' => 'Sócio / Fiduciante',
            ],
            self::Receivables => [
                'portfolio' => 'Carteira',
                'construction' => 'Empreendimento',
                'contracts' => 'Contratos',
                'assigned_percentage' => 'Percentual cedido',
                'receiving_account' => 'Conta de recebimento',
                'eligibility_criteria' => 'Critério de elegibilidade',
            ],
            self::FundAccount => [
                'fund_type' => 'Tipo de fundo',
                'bank' => 'Banco',
                'agency' => 'Agência',
                'account' => 'Conta',
                'composition_rule' => 'Regra de composição',
            ],
            self::Inventory => [
                'construction' => 'Empreendimento',
                'units' => 'Unidades',
                'pledged_percentage' => 'Percentual dado em garantia',
            ],
            self::Personal => [
                'guarantor' => 'Garantidor',
                'tax_id' => 'CPF / CNPJ',
                'liability_limit' => 'Limite de responsabilidade',
            ],
            self::Insurance => [
                'issuer' => 'Seguradora / Banco emissor',
                'policy_number' => 'Número da apólice / carta',
                'expires_at' => 'Vencimento',
            ],
            self::Other => [
                'identification' => 'Identificação',
            ],
        };
    }
}
