<?php

namespace App\Services\Funds;

use App\DTOs\Funds\FundAllocationSuggestion;
use App\Exceptions\Funds\FundAllocationException;
use App\Models\BankApplication;
use App\Models\BankApplicationBalanceSnapshot;
use App\Models\Fund;
use App\Models\FundAllocationLine;
use App\Models\FundAllocationPeriod;
use App\Models\FundAllocationRevision;
use App\Models\User;
use App\Support\Funds\FundAllocationMoney;
use Illuminate\Support\Collection;

/**
 * First approved suggestion strategy: replay the proportions of the latest
 * approved earlier competence for the same BankApplication.
 *
 * Suggestions are proposals only. This service reads approved history and the
 * current reference balance and returns exact cent amounts; it never writes,
 * never approves and never touches an existing draft. The caller presents the
 * breakdown for explicit review and, only on confirmation, materializes it
 * through the usual draft creation (which re-validates everything).
 *
 * Share preservation: proportions are computed against the PRIOR reference
 * balance, unallocated share included, and applied to the CURRENT reference.
 * A prior 30% / 20% / 50%-unallocated split over R$ 100.000 becomes
 * R$ 36.000 / R$ 24.000 / R$ 60.000 unallocated over R$ 120.000 — the
 * allocated shares are never renormalized to 100%, and the unallocated
 * remainder is never silently distributed among the funds.
 *
 * Rounding: exact integer math (bcmath for the intermediate products, which
 * can exceed 64-bit), largest-remainder distribution of leftover cents with
 * a deterministic tiebreak (remainder desc, fund id asc). The suggested
 * total never exceeds the reference balance.
 *
 * Participation changes: a previously participating fund that is no longer
 * linked keeps its share unallocated (reported as missing, never reassigned
 * to another fund); a newly linked fund is reported explicitly with a zero
 * suggestion, awaiting manual review.
 */
class FundAllocationSuggestionService
{
    public function suggest(
        User $actor,
        BankApplication $application,
        string $competence,
        ?int $snapshotId = null,
    ): FundAllocationSuggestion {
        if (! $actor->can(FundAllocationService::PERMISSION_VIEW)) {
            throw FundAllocationException::forbidden('visualizar alocações');
        }

        $competenceDate = FundAllocationService::normalizeCompetence($competence);
        $applicationId = (int) $application->getKey();

        $snapshot = $this->resolveSnapshot($application, $competenceDate, $snapshotId);
        $funds = $this->eligibleFunds($applicationId);

        if (! $snapshot instanceof BankApplicationBalanceSnapshot) {
            return new FundAllocationSuggestion(
                bankApplicationId: $applicationId,
                competence: $competenceDate,
                snapshotId: null,
                snapshotVersion: null,
                referenceCents: 0,
                sourceRevisionId: null,
                sourceCompetence: null,
                lines: $this->zeroLines($funds),
                missingFunds: [],
                newFundIds: $funds->pluck('id')->all(),
                totalCents: 0,
                unallocatedCents: 0,
                reason: FundAllocationSuggestion::REASON_NO_REFERENCE,
            );
        }

        $referenceCents = FundAllocationMoney::toCents((string) $snapshot->amount);

        $source = $this->latestApprovedEarlierRevision($applicationId, $competenceDate);

        if (! $source instanceof FundAllocationRevision) {
            return new FundAllocationSuggestion(
                bankApplicationId: $applicationId,
                competence: $competenceDate,
                snapshotId: $snapshot->getKey(),
                snapshotVersion: $snapshot->version,
                referenceCents: $referenceCents,
                sourceRevisionId: null,
                sourceCompetence: null,
                lines: $this->zeroLines($funds),
                missingFunds: [],
                newFundIds: $funds->pluck('id')->all(),
                totalCents: 0,
                unallocatedCents: $referenceCents,
                reason: FundAllocationSuggestion::REASON_NO_HISTORY,
            );
        }

        $priorReferenceCents = FundAllocationMoney::toCents((string) $source->reference_amount);

        if ($priorReferenceCents <= 0) {
            return new FundAllocationSuggestion(
                bankApplicationId: $applicationId,
                competence: $competenceDate,
                snapshotId: $snapshot->getKey(),
                snapshotVersion: $snapshot->version,
                referenceCents: $referenceCents,
                sourceRevisionId: $source->getKey(),
                sourceCompetence: $source->period?->competence->toDateString(),
                lines: $this->zeroLines($funds),
                missingFunds: [],
                newFundIds: $funds->pluck('id')->all(),
                totalCents: 0,
                unallocatedCents: $referenceCents,
                reason: FundAllocationSuggestion::REASON_ZERO_REFERENCE,
            );
        }

        $priorLines = $source->lines()->orderBy('fund_id')->get()->keyBy('fund_id');
        $linkedIds = $funds->pluck('id')->all();

        $missing = [];
        foreach ($priorLines as $fundId => $line) {
            if (! in_array($fundId, $linkedIds, true)) {
                $missing[] = [
                    'fund_id' => $fundId,
                    'prior_cents' => FundAllocationMoney::toCents((string) $line->amount),
                ];
            }
        }

        // Participating lines only: missing funds stay out of the numerator,
        // so their prior share remains unallocated instead of leaking into
        // another fund's suggestion.
        $participating = [];
        foreach ($funds as $fund) {
            $prior = $priorLines->get($fund->id);
            $participating[] = [
                'fund_id' => (int) $fund->id,
                'emission_id' => (int) $fund->emission_id,
                'prior_cents' => $prior instanceof FundAllocationLine
                    ? FundAllocationMoney::toCents((string) $prior->amount)
                    : null,
            ];
        }

        $distributed = $this->distribute(
            referenceCents: $referenceCents,
            priorReferenceCents: $priorReferenceCents,
            participating: $participating,
        );

        $lines = [];
        $newFundIds = [];
        $totalCents = 0;
        foreach ($participating as $index => $row) {
            $suggested = $row['prior_cents'] === null ? 0 : $distributed[$index];
            $lines[] = [
                'fund_id' => $row['fund_id'],
                'emission_id' => $row['emission_id'],
                'suggested_cents' => $suggested,
                'prior_cents' => $row['prior_cents'],
                'is_new' => $row['prior_cents'] === null,
            ];

            if ($row['prior_cents'] === null) {
                $newFundIds[] = $row['fund_id'];
            }

            $totalCents += $suggested;
        }

        return new FundAllocationSuggestion(
            bankApplicationId: $applicationId,
            competence: $competenceDate,
            snapshotId: $snapshot->getKey(),
            snapshotVersion: $snapshot->version,
            referenceCents: $referenceCents,
            sourceRevisionId: $source->getKey(),
            sourceCompetence: $source->period?->competence->toDateString(),
            lines: $lines,
            missingFunds: $missing,
            newFundIds: $newFundIds,
            totalCents: $totalCents,
            unallocatedCents: $referenceCents - $totalCents,
            reason: null,
        );
    }

    private function resolveSnapshot(
        BankApplication $application,
        string $competenceDate,
        ?int $snapshotId,
    ): ?BankApplicationBalanceSnapshot {
        if ($snapshotId !== null) {
            $snapshot = BankApplicationBalanceSnapshot::query()->whereKey($snapshotId)->first();

            if (! $snapshot instanceof BankApplicationBalanceSnapshot
                || (int) $snapshot->bank_application_id !== (int) $application->getKey()
                || $snapshot->competence->toDateString() !== $competenceDate
            ) {
                throw FundAllocationException::invalid('O saldo de referência informado não pertence a esta aplicação e competência.');
            }

            return $snapshot;
        }

        return BankApplicationBalanceSnapshot::query()
            ->where('bank_application_id', $application->getKey())
            ->whereDate('competence', $competenceDate)
            ->orderByDesc('version')
            ->first();
    }

    /**
     * @return Collection<int, Fund>
     */
    private function eligibleFunds(int $applicationId)
    {
        return Fund::query()
            ->where('bank_application_id', $applicationId)
            ->orderBy('id')
            ->get();
    }

    private function latestApprovedEarlierRevision(
        int $applicationId,
        string $competenceDate,
    ): ?FundAllocationRevision {
        $period = FundAllocationPeriod::query()
            ->where('bank_application_id', $applicationId)
            ->whereDate('competence', '<', $competenceDate)
            ->whereNotNull('effective_revision_id')
            ->orderByDesc('competence')
            ->first();

        if (! $period instanceof FundAllocationPeriod) {
            return null;
        }

        $revision = FundAllocationRevision::query()
            ->whereKey($period->effective_revision_id)
            ->where('status', FundAllocationRevision::STATUS_APPROVED)
            ->with('period')
            ->first();

        return $revision;
    }

    /**
     * @param  Collection<int, Fund>  $funds
     * @return list<array{fund_id: int, emission_id: int, suggested_cents: int, prior_cents: int|null, is_new: bool}>
     */
    private function zeroLines($funds): array
    {
        return $funds->map(fn (Fund $fund): array => [
            'fund_id' => (int) $fund->id,
            'emission_id' => (int) $fund->emission_id,
            'suggested_cents' => 0,
            'prior_cents' => null,
            'is_new' => true,
        ])->values()->all();
    }

    /**
     * Largest-remainder distribution over participating funds.
     *
     * Each fund's exact share is reference × prior / priorReference. Floors
     * are taken per fund and the leftover cents (always fewer than the fund
     * count) go to the largest fractional remainders, fund id ascending on
     * ties. The allocated target itself rounds down, so the total can never
     * exceed the reference and the unallocated remainder absorbs every
     * rounding difference.
     *
     * @param  list<array{fund_id: int, emission_id: int, prior_cents: int|null}>  $participating
     * @return list<int> suggested cents aligned with $participating
     */
    private function distribute(int $referenceCents, int $priorReferenceCents, array $participating): array
    {
        $floors = [];
        $remainders = [];
        $priorTotal = 0;

        foreach ($participating as $index => $row) {
            if ($row['prior_cents'] === null) {
                $floors[$index] = 0;
                $remainders[$index] = -1;

                continue;
            }

            $priorTotal += $row['prior_cents'];

            // Exact: numerator can exceed 64-bit (cents²), hence bcmath.
            $numerator = bcmul((string) $referenceCents, (string) $row['prior_cents']);
            $floors[$index] = (int) bcdiv($numerator, (string) $priorReferenceCents, 0);
            $remainders[$index] = (int) bcmod($numerator, (string) $priorReferenceCents);
        }

        $target = (int) bcdiv(
            bcmul((string) $referenceCents, (string) $priorTotal),
            (string) $priorReferenceCents,
            0
        );

        $leftover = $target - array_sum($floors);

        if ($leftover > 0) {
            $order = array_keys($participating);
            usort($order, function (int $a, int $b) use ($remainders, $participating): int {
                if ($remainders[$a] !== $remainders[$b]) {
                    return $remainders[$b] <=> $remainders[$a];
                }

                return $participating[$a]['fund_id'] <=> $participating[$b]['fund_id'];
            });

            // $leftover is always smaller than the fund count (largest-remainder
            // property), and funds without history sort last, so only funds
            // with a prior share receive the extra cents.
            for ($i = 0; $i < $leftover; $i++) {
                $floors[$order[$i]]++;
            }
        }

        ksort($floors);

        return array_values($floors);
    }
}
