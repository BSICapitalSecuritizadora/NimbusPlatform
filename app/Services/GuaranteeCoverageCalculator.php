<?php

namespace App\Services;

use App\Models\Emission;
use App\Models\Fund;
use App\Models\FundBalanceHistory;
use App\Models\GuaranteeSnapshot;
use App\Models\IntegralizationHistory;
use App\Models\PuHistory;
use App\Models\Receivable;
use App\Models\SalesBoard;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class GuaranteeCoverageCalculator
{
    /**
     * @return array{
     *     account_balance_value: float,
     *     automatic_guarantees_value: float,
     *     coverage_ratio: float|null,
     *     missing_sources: array<int, string>,
     *     outstanding_balance_value: float,
     *     quota_value: float,
     *     receivables_value: float,
     *     reference_month: string,
     *     reference_month_label: string,
     *     total_guarantees_value: float,
     *     units_value: float,
     *     updated_at: \Illuminate\Support\Carbon|null
     * }|null
     */
    public function buildLatestSummary(Emission $emission): ?array
    {
        return $this->buildHistory($emission)->first();
    }

    /**
     * @return Collection<int, array{
     *     account_balance_value: float,
     *     automatic_guarantees_value: float,
     *     coverage_ratio: float|null,
     *     missing_sources: array<int, string>,
     *     outstanding_balance_value: float,
     *     quota_value: float,
     *     receivables_value: float,
     *     reference_month: string,
     *     reference_month_label: string,
     *     total_guarantees_value: float,
     *     units_value: float,
     *     updated_at: \Illuminate\Support\Carbon|null
     * }>
     */
    public function buildHistory(Emission $emission): Collection
    {
        if (! Emission::hasGuaranteeSnapshotsTable()) {
            return collect();
        }

        $emission->load([
            'guaranteeSnapshots',
            'salesBoards',
            'receivables',
            'funds.balanceHistories',
            'puHistories',
            'integralizationHistories',
        ]);

        /** @var Collection<int|string, Collection<int, SalesBoard>> $salesBoardsByConstruction */
        $salesBoardsByConstruction = $emission->salesBoards
            ->filter(fn (SalesBoard $salesBoard): bool => $salesBoard->reference_month !== null)
            ->sortByDesc(fn (SalesBoard $salesBoard): string => $salesBoard->reference_month?->copy()->startOfMonth()->toDateString() ?? '')
            ->groupBy('construction_id');

        /** @var Collection<string, Receivable> $receivablesByMonth */
        $receivablesByMonth = $emission->receivables
            ->filter(fn (Receivable $receivable): bool => $receivable->reference_month !== null)
            ->keyBy(fn (Receivable $receivable): string => $receivable->reference_month->copy()->startOfMonth()->toDateString());

        return $emission->guaranteeSnapshots
            ->filter(fn (GuaranteeSnapshot $snapshot): bool => $snapshot->reference_month !== null)
            ->sortByDesc('reference_month')
            ->values()
            ->map(fn (GuaranteeSnapshot $snapshot): array => $this->buildSnapshotSummary(
                emission: $emission,
                snapshot: $snapshot,
                salesBoardsByConstruction: $salesBoardsByConstruction,
                receivablesByMonth: $receivablesByMonth,
            ));
    }

    /**
     * @param  Collection<int|string, Collection<int, SalesBoard>>  $salesBoardsByConstruction
     * @param  Collection<string, Receivable>  $receivablesByMonth
     * @return array{
     *     account_balance_value: float,
     *     automatic_guarantees_value: float,
     *     coverage_ratio: float|null,
     *     missing_sources: array<int, string>,
     *     outstanding_balance_value: float,
     *     quota_value: float,
     *     receivables_value: float,
     *     reference_month: string,
     *     reference_month_label: string,
     *     total_guarantees_value: float,
     *     units_value: float,
     *     updated_at: \Illuminate\Support\Carbon|null
     * }
     */
    private function buildSnapshotSummary(
        Emission $emission,
        GuaranteeSnapshot $snapshot,
        Collection $salesBoardsByConstruction,
        Collection $receivablesByMonth,
    ): array {
        $referenceMonth = $snapshot->reference_month->copy()->startOfMonth()->toDateString();

        /** @var Collection<int, SalesBoard> $salesBoards */
        $salesBoards = $this->resolveSalesBoardsForReferenceMonth(
            salesBoardsByConstruction: $salesBoardsByConstruction,
            referenceMonth: $referenceMonth,
        );
        $receivable = $receivablesByMonth->get($referenceMonth);

        $unitsValue = round((float) $salesBoards->sum(fn (SalesBoard $salesBoard): float => (float) $salesBoard->stock_value), 2);
        $receivablesValue = $receivable instanceof Receivable
            ? $this->calculateReceivablesValue($receivable)
            : 0.0;

        [$accountBalanceValue, $hasFundData] = $this->resolveAccountBalanceValue($emission, $referenceMonth);
        [$outstandingBalanceValue, $hasOutstandingBalanceData] = $this->resolveOutstandingBalanceValue($emission, $referenceMonth);

        $quotaValue = round((float) $snapshot->quota_value, 2);
        $automaticGuaranteesValue = round($unitsValue + $receivablesValue + $accountBalanceValue, 2);
        $totalGuaranteesValue = round($quotaValue + $automaticGuaranteesValue, 2);

        $missingSources = [];

        if ($salesBoards->isEmpty()) {
            $missingSources[] = 'Quadro de vendas';
        }

        if (! $receivable instanceof Receivable) {
            $missingSources[] = 'Recebiveis';
        }

        if (! $hasFundData) {
            $missingSources[] = 'Fundos';
        }

        if (! $hasOutstandingBalanceData) {
            $missingSources[] = 'Historico de PU';
        }

        return [
            'reference_month' => $referenceMonth,
            'reference_month_label' => GuaranteeSnapshot::formatReferenceMonthForDisplay($referenceMonth),
            'quota_value' => $quotaValue,
            'units_value' => $unitsValue,
            'receivables_value' => $receivablesValue,
            'account_balance_value' => $accountBalanceValue,
            'automatic_guarantees_value' => $automaticGuaranteesValue,
            'total_guarantees_value' => $totalGuaranteesValue,
            'outstanding_balance_value' => $outstandingBalanceValue,
            'coverage_ratio' => $this->calculateCoverageRatio(
                quotaValue: $quotaValue,
                unitsValue: $unitsValue,
                receivablesValue: $receivablesValue,
                accountBalanceValue: $accountBalanceValue,
                outstandingBalanceValue: $outstandingBalanceValue,
            ),
            'missing_sources' => $missingSources,
            'updated_at' => $snapshot->updated_at,
        ];
    }

    public function calculateOutstandingBalanceForMonth(Emission $emission, string $referenceMonth): float
    {
        $emission->load([
            'puHistories',
            'integralizationHistories',
        ]);

        [$outstandingBalanceValue] = $this->resolveOutstandingBalanceValue($emission, $referenceMonth);

        return $outstandingBalanceValue;
    }

    /**
     * @param  Collection<int|string, Collection<int, SalesBoard>>  $salesBoardsByConstruction
     * @return Collection<int, SalesBoard>
     */
    private function resolveSalesBoardsForReferenceMonth(
        Collection $salesBoardsByConstruction,
        string $referenceMonth,
    ): Collection {
        return $salesBoardsByConstruction
            ->map(function (Collection $salesBoards) use ($referenceMonth): ?SalesBoard {
                /** @var SalesBoard|null $latestSalesBoard */
                $latestSalesBoard = $salesBoards->first(
                    fn (SalesBoard $salesBoard): bool => $salesBoard->reference_month?->copy()->startOfMonth()->toDateString() <= $referenceMonth,
                );

                return $latestSalesBoard;
            })
            ->filter(fn (?SalesBoard $salesBoard): bool => $salesBoard instanceof SalesBoard)
            ->values();
    }

    private function calculateReceivablesValue(Receivable $receivable): float
    {
        return round(
            (float) $receivable->received_installment_interest_amount
            + (float) $receivable->received_installment_amortization_amount
            + (float) $receivable->received_prepayment_interest_amount
            + (float) $receivable->received_prepayment_amortization_amount
            + (float) $receivable->received_default_interest_amount
            + (float) $receivable->received_default_amortization_amount
            + (float) $receivable->received_interest_and_penalty_amount,
            2,
        );
    }

    /**
     * @return array{0: float, 1: bool}
     */
    private function resolveOutstandingBalanceValue(Emission $emission, string $referenceMonth): array
    {
        $referenceStart = Carbon::parse($referenceMonth)->startOfMonth();
        $referenceEnd = $referenceStart->copy()->endOfMonth();
        $referenceEndString = $referenceEnd->toDateString();

        $integralizedQuantity = round(
            (float) $emission->integralizationHistories
                ->filter(function (IntegralizationHistory $integralizationHistory) use ($referenceEndString): bool {
                    $historyDate = $integralizationHistory->date?->toDateString();

                    return filled($historyDate) && $historyDate <= $referenceEndString;
                })
                ->sum('quantity'),
            4,
        );

        if ($integralizedQuantity <= 0) {
            return [0.0, true];
        }

        $monthStartString = $referenceStart->toDateString();

        /** @var PuHistory|null $latestPuHistory */
        $latestPuHistory = $emission->puHistories
            ->filter(function (PuHistory $puHistory) use ($monthStartString, $referenceEndString): bool {
                $historyDate = $puHistory->date?->toDateString();

                return filled($historyDate)
                    && ($historyDate >= $monthStartString)
                    && ($historyDate <= $referenceEndString);
            })
            ->sortByDesc(fn (PuHistory $puHistory): string => $puHistory->date?->toDateString() ?? '')
            ->first();

        if (! $latestPuHistory instanceof PuHistory) {
            return [0.0, false];
        }

        return [
            round((float) $latestPuHistory->unit_value * $integralizedQuantity, 2),
            true,
        ];
    }

    /**
     * @return array{0: float, 1: bool}
     */
    private function resolveAccountBalanceValue(Emission $emission, string $referenceMonth): array
    {
        $totalBalance = 0.0;
        $hasData = false;

        foreach ($emission->funds as $fund) {
            $fundBalance = $this->resolveFundBalanceForMonth($fund, $referenceMonth);

            if ($fundBalance === null) {
                continue;
            }

            $hasData = true;
            $totalBalance += $fundBalance;
        }

        return [round($totalBalance, 2), $hasData];
    }

    private function resolveFundBalanceForMonth(Fund $fund, string $referenceMonth): ?float
    {
        if (
            ($fund->balance !== null)
            && ($fund->balance_updated_at !== null)
            && ($fund->balance_updated_at->copy()->startOfMonth()->toDateString() === $referenceMonth)
        ) {
            return round((float) $fund->balance, 2);
        }

        $history = $fund->balanceHistories
            ->first(fn (FundBalanceHistory $balanceHistory): bool => $balanceHistory->date?->copy()->startOfMonth()->toDateString() === $referenceMonth);

        if (! $history instanceof FundBalanceHistory) {
            return null;
        }

        return round((float) $history->balance, 2);
    }

    private function calculateCoverageRatio(
        float $quotaValue,
        float $unitsValue,
        float $receivablesValue,
        float $accountBalanceValue,
        float $outstandingBalanceValue,
    ): ?float {
        if ($outstandingBalanceValue <= 0) {
            return null;
        }

        return round(
            ($quotaValue + $unitsValue + $receivablesValue + $accountBalanceValue) / $outstandingBalanceValue,
            6,
        );
    }
}
