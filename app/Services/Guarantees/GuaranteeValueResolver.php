<?php

namespace App\Services\Guarantees;

use App\DTOs\Guarantees\ResolvedGuaranteeValue;
use App\Enums\GuaranteeValueSource;
use App\Models\Guarantee;
use App\Models\GuaranteeMonthlyPosition;
use App\Models\Receivable;
use App\Models\SalesBoard;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Descobre quanto uma garantia vale numa competência, lendo os dados
 * operacionais que o Nimbus já possui (§15 a §21 do escopo).
 *
 * Regra que atravessa todo o serviço: valor ausente devolve `pending`, nunca
 * zero. Uma conta vinculada realmente zerada e uma conta que ninguém informou
 * levam a decisões opostas, e o motor não pode confundi-las.
 */
class GuaranteeValueResolver
{
    /**
     * Valor atual da garantia na competência.
     *
     * `$manualPositions` traz os valores já digitados para o mês, indexados por
     * `guarantee_id`, para que a fonte manual não faça uma consulta por garantia.
     *
     * @param  Collection<int, GuaranteeMonthlyPosition>  $manualPositions
     */
    public function resolve(
        Guarantee $guarantee,
        string $referenceMonth,
        EmissionOperationalDataset $dataset,
        Collection $manualPositions,
    ): ResolvedGuaranteeValue {
        if ($guarantee->type !== null && ! $guarantee->type->hasMonetaryPosition()) {
            return ResolvedGuaranteeValue::notApplicable([
                'reason' => 'Garantia pessoal: responde por limite de responsabilidade, não por valor de mercado.',
            ]);
        }

        $source = $guarantee->resolvedValueSource();

        $manualValue = $this->resolveManualValue($guarantee, $manualPositions);

        // Um valor digitado para a competência prevalece sobre a fonte
        // automática: quem informou o número estava corrigindo o sistema.
        if ($manualValue !== null) {
            return ResolvedGuaranteeValue::manual($manualValue, ['overrides_source' => $source->value]);
        }

        return match ($source) {
            GuaranteeValueSource::ReceivablesPortfolio => $this->resolveFromReceivables($guarantee, $referenceMonth, $dataset),
            GuaranteeValueSource::SalesBoard => $this->resolveFromSalesBoards($guarantee, $referenceMonth, $dataset),
            GuaranteeValueSource::FundBalance => $this->resolveFromFunds($guarantee, $referenceMonth, $dataset),
            GuaranteeValueSource::Valuation => $this->resolveFromValuation($guarantee, $referenceMonth),
            GuaranteeValueSource::Manual => ResolvedGuaranteeValue::pending(GuaranteeValueSource::Manual, [
                'reason' => 'Valor da competência ainda não informado.',
            ]),
            GuaranteeValueSource::NotAvailable => ResolvedGuaranteeValue::notApplicable(),
        };
    }

    /**
     * @param  Collection<int, GuaranteeMonthlyPosition>  $manualPositions
     */
    private function resolveManualValue(Guarantee $guarantee, Collection $manualPositions): ?float
    {
        $position = $manualPositions->get($guarantee->getKey());

        if (! $position instanceof GuaranteeMonthlyPosition) {
            return null;
        }

        if (! $position->value_status?->isResolved()) {
            return null;
        }

        if ($position->value_source !== GuaranteeValueSource::Manual) {
            return null;
        }

        return $position->current_value === null ? null : (float) $position->current_value;
    }

    /**
     * Recebíveis cedidos (§17).
     *
     * Usa o saldo adimplente pós-evento — a parcela da carteira que de fato
     * responde pela garantia. Distratos, cancelamentos e inadimplência já estão
     * fora dessa medida, então não há o que subtrair depois. Se o percentual
     * cedido estiver identificado, o saldo é proporcionalizado.
     */
    private function resolveFromReceivables(
        Guarantee $guarantee,
        string $referenceMonth,
        EmissionOperationalDataset $dataset,
    ): ResolvedGuaranteeValue {
        $receivable = $dataset->receivableForMonth($referenceMonth);

        if (! $receivable instanceof Receivable) {
            return ResolvedGuaranteeValue::pending(GuaranteeValueSource::ReceivablesPortfolio, [
                'reason' => 'Sem resumo de recebíveis para a competência.',
            ]);
        }

        $performingBalance = $receivable->performing_balance_post_event_amount
            ?? $receivable->total_outstanding_balance_amount;

        if ($performingBalance === null) {
            return ResolvedGuaranteeValue::pending(GuaranteeValueSource::ReceivablesPortfolio, [
                'reason' => 'Resumo de recebíveis sem saldo adimplente informado.',
            ]);
        }

        $assignedShare = $this->assignedShare($guarantee);
        $value = round((float) $performingBalance * $assignedShare, 2);

        return ResolvedGuaranteeValue::automatic($value, GuaranteeValueSource::ReceivablesPortfolio, [
            'receivable_id' => $receivable->getKey(),
            'performing_balance' => round((float) $performingBalance, 2),
            'assigned_share' => $assignedShare,
            'active_contracts_count' => $receivable->active_contracts_count,
        ]);
    }

    /**
     * Estoque e unidades (§18): valor em estoque do quadro de vendas mais
     * recente de cada empreendimento até a competência.
     *
     * Quando a garantia aponta para um empreendimento específico, só ele entra —
     * somar a carteira inteira inflaria a cobertura de uma garantia que recai
     * sobre um único ativo.
     */
    private function resolveFromSalesBoards(
        Guarantee $guarantee,
        string $referenceMonth,
        EmissionOperationalDataset $dataset,
    ): ResolvedGuaranteeValue {
        $salesBoards = $dataset->salesBoardsForMonth($referenceMonth, $guarantee->construction_id);

        if ($salesBoards->isEmpty()) {
            return ResolvedGuaranteeValue::pending(GuaranteeValueSource::SalesBoard, [
                'reason' => 'Sem quadro de vendas para a competência.',
            ]);
        }

        $stockValue = round(
            (float) $salesBoards->sum(fn (SalesBoard $salesBoard): float => (float) $salesBoard->stock_value),
            2,
        );
        $stockUnits = (int) $salesBoards->sum(fn (SalesBoard $salesBoard): int => (int) $salesBoard->stock_units);

        $pledgedShare = $this->pledgedShare($guarantee);
        $value = round($stockValue * $pledgedShare, 2);

        return ResolvedGuaranteeValue::automatic($value, GuaranteeValueSource::SalesBoard, [
            'stock_value' => $stockValue,
            'stock_units' => $stockUnits,
            'pledged_share' => $pledgedShare,
            'sales_board_ids' => $salesBoards->pluck('id')->all(),
            'average_unit_value' => $stockUnits > 0 ? round($stockValue / $stockUnits, 2) : null,
        ]);
    }

    /**
     * Fundos e contas vinculadas (§19): saldo da conta na competência.
     *
     * A garantia amarrada a um fundo lê só aquele fundo. Sem amarração, soma os
     * fundos da emissão — comportamento que o cálculo anterior já tinha.
     */
    private function resolveFromFunds(
        Guarantee $guarantee,
        string $referenceMonth,
        EmissionOperationalDataset $dataset,
    ): ResolvedGuaranteeValue {
        $funds = $dataset->fundsFor($guarantee->fund_id);

        if ($funds->isEmpty()) {
            return ResolvedGuaranteeValue::pending(GuaranteeValueSource::FundBalance, [
                'reason' => 'Nenhuma conta vinculada associada à garantia.',
            ]);
        }

        $total = 0.0;
        $hasData = false;
        $details = [];

        foreach ($funds as $fund) {
            $balance = $dataset->fundBalanceForMonth($fund, $referenceMonth);

            if ($balance === null) {
                continue;
            }

            $hasData = true;
            $total += $balance;
            $details[] = ['fund_id' => $fund->getKey(), 'balance' => $balance];
        }

        if (! $hasData) {
            return ResolvedGuaranteeValue::pending(GuaranteeValueSource::FundBalance, [
                'reason' => 'Sem saldo informado para a competência.',
            ]);
        }

        return ResolvedGuaranteeValue::automatic(round($total, 2), GuaranteeValueSource::FundBalance, [
            'funds' => $details,
        ]);
    }

    /**
     * Avaliações (§20): a vigente na competência, nunca a mais recente em termos
     * absolutos — um laudo posterior não reescreve um mês já apurado.
     */
    private function resolveFromValuation(Guarantee $guarantee, string $referenceMonth): ResolvedGuaranteeValue
    {
        $guarantee->loadMissing('valuations');

        $referenceEnd = Carbon::parse($referenceMonth)->endOfMonth();
        $valuation = $guarantee->valuationAsOf($referenceEnd);

        if ($valuation === null) {
            return ResolvedGuaranteeValue::pending(GuaranteeValueSource::Valuation, [
                'reason' => 'Nenhuma avaliação vigente na competência.',
            ]);
        }

        return ResolvedGuaranteeValue::automatic((float) $valuation->value, GuaranteeValueSource::Valuation, [
            'valuation_id' => $valuation->getKey(),
            'valuation_date' => $valuation->valuation_date?->toDateString(),
            'basis' => $valuation->basis?->value,
            'appraiser' => $valuation->appraiser,
            'is_expired' => $valuation->isExpiredOn($referenceEnd),
        ]);
    }

    /**
     * Percentual cedido da carteira. Ausente significa carteira integral, não
     * zero — uma cessão sem percentual declarado é cessão de 100%.
     */
    private function assignedShare(Guarantee $guarantee): float
    {
        return $this->percentageFromIdentification($guarantee, 'assigned_percentage');
    }

    private function pledgedShare(Guarantee $guarantee): float
    {
        return $this->percentageFromIdentification($guarantee, 'pledged_percentage');
    }

    /**
     * Aceita o percentual tanto como fração (0,8) quanto como número percentual
     * (80), porque os dois aparecem nos contratos e na digitação.
     */
    private function percentageFromIdentification(Guarantee $guarantee, string $key): float
    {
        $raw = ($guarantee->identification ?? [])[$key] ?? null;

        if (! is_numeric($raw)) {
            return 1.0;
        }

        $value = (float) $raw;

        if ($value <= 0.0) {
            return 1.0;
        }

        return $value > 1.0 ? min($value / 100, 1.0) : $value;
    }
}
