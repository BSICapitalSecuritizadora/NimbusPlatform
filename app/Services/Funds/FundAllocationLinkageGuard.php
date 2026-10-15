<?php

namespace App\Services\Funds;

use App\Exceptions\Funds\FundAllocationException;
use App\Models\Fund;
use App\Models\FundAllocationPeriod;
use App\Models\FundAllocationRevision;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Blocks Fund linkage edits that would silently stale an open allocation.
 *
 * A Fund's emission_id, bank_account_id and bank_application_id are the exact
 * relationships every allocation line validates against. Changing any of them
 * while a draft or pending-review revision references the Fund would leave
 * that revision validating against linkage that no longer exists — the
 * approval would then reject it, wasting the review cycle, or worse, race
 * the approval itself.
 *
 * Policy:
 * - Open (draft or pending-review) revisions referencing the Fund block the
 *   linkage change with an actionable message. The user discards or concludes
 *   the open revision first, then reassociates the Fund.
 * - Approved, superseded and discarded revisions never block: approved lines
 *   carry their frozen emission_id, so history stays byte-identical while the
 *   Fund moves on. Reassociation is never permanently prohibited.
 * - Changes to any other attribute always pass through untouched.
 *
 * Locking: this guard intentionally takes no locks. It runs inside Fund's
 * saving event, where the Fund row write is already pending — acquiring
 * application or period locks here would invert the service's global order
 * (application → period → revision → fund) and risk deadlocks with
 * approvals. The truly concurrent race is closed on the approval side, which
 * holds the Fund rows FOR UPDATE while validating; this guard covers the
 * sequential path (and every writer: UI, console, jobs) with a clear,
 * domain-level refusal.
 */
class FundAllocationLinkageGuard
{
    /**
     * @var list<string>
     */
    public const LINKAGE_ATTRIBUTES = [
        'emission_id',
        'bank_account_id',
        'bank_application_id',
    ];

    public function assertLinkageChangeAllowed(Fund $fund): void
    {
        if (! $fund->exists) {
            return;
        }

        $changed = false;

        foreach (self::LINKAGE_ATTRIBUTES as $attribute) {
            if ($fund->isDirty($attribute)) {
                $changed = true;

                break;
            }
        }

        if (! $changed) {
            return;
        }

        $open = $this->openRevisionsForFund((int) $fund->getKey());

        if ($open->isEmpty()) {
            return;
        }

        $competences = $open
            ->map(fn (array $row): string => $row['competence'])
            ->unique()
            ->values()
            ->all();

        throw FundAllocationException::invalid(
            'Este fundo participa de uma alocação em andamento ('.$this->formatCompetences($competences).'). '.
            'Conclua ou descarte a revisão aberta antes de alterar a operação, a conta ou a aplicação vinculada.'
        );
    }

    /**
     * Whether the Fund is referenced by any open allocation revision.
     *
     * Unlocked by design (see the class documentation): a missed row here only
     * delays the refusal to submission or approval, which re-validate under
     * locks and reject the stale revision there.
     */
    public function hasOpenRevisionForFund(int $fundId): bool
    {
        return $this->openRevisionsQuery($fundId)->exists();
    }

    /**
     * @return Collection<int, array{revision_id: int, status: string, competence: string}>
     */
    public function openRevisionsForFund(int $fundId): Collection
    {
        return $this->openRevisionsQuery($fundId)
            ->with('period')
            ->orderBy('fund_allocation_revisions.id')
            ->get()
            ->map(fn (FundAllocationRevision $revision): array => [
                'revision_id' => $revision->getKey(),
                'status' => $revision->status,
                'competence' => $revision->period?->competence->format('m/Y') ?? '—',
            ]);
    }

    /**
     * @return Builder<FundAllocationRevision>
     */
    private function openRevisionsQuery(int $fundId)
    {
        return FundAllocationRevision::query()
            ->whereIn('status', [
                FundAllocationRevision::STATUS_DRAFT,
                FundAllocationRevision::STATUS_PENDING_REVIEW,
            ])
            ->whereIn('id', FundAllocationPeriod::query()
                ->whereNotNull('open_revision_id')
                ->select('open_revision_id'))
            ->whereHas('lines', fn ($query) => $query->where('fund_id', $fundId));
    }

    /**
     * @param  list<string>  $competences
     */
    private function formatCompetences(array $competences): string
    {
        $labeled = array_map(
            fn (string $competence): string => "competência {$competence}",
            $competences
        );

        if (count($labeled) === 1) {
            return $labeled[0];
        }

        $last = array_pop($labeled);

        return implode(', ', $labeled).' e '.$last;
    }
}
