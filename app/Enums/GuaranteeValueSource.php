<?php

namespace App\Enums;

/**
 * De onde o Nimbus lê o valor atual de uma garantia numa competência.
 *
 * `Manual` significa que o dado existe mas depende de digitação; `NotAvailable`
 * significa que o sistema não tem como obtê-lo nem sabe a quem pedir — os dois
 * são deliberadamente distintos porque só o primeiro vira pendência acionável.
 */
enum GuaranteeValueSource: string
{
    case ReceivablesPortfolio = 'receivables_portfolio';
    case SalesBoard = 'sales_board';
    case FundBalance = 'fund_balance';
    case Valuation = 'valuation';
    case Manual = 'manual';
    case NotAvailable = 'not_available';

    public function label(): string
    {
        return match ($this) {
            self::ReceivablesPortfolio => 'Carteira de recebíveis',
            self::SalesBoard => 'Quadro de vendas',
            self::FundBalance => 'Conta vinculada',
            self::Valuation => 'Avaliação',
            self::Manual => 'Manual',
            self::NotAvailable => 'Sem fonte disponível',
        };
    }

    public function isAutomatic(): bool
    {
        return match ($this) {
            self::ReceivablesPortfolio,
            self::SalesBoard,
            self::FundBalance => true,
            default => false,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $source) {
            $options[$source->value] = $source->label();
        }

        return $options;
    }
}
