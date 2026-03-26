<?php

namespace App\Actions\Proposals;

use App\Models\Proposal;
use App\Models\ProposalAssignment;
use App\Models\ProposalDistributionState;
use App\Models\ProposalRepresentative;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AssignProposalRepresentative
{
    public function handle(Proposal $proposal): ?ProposalAssignment
    {
        if ($proposal->assigned_representative_id) {
            return $proposal->assignments()->latest('sequence')->first();
        }

        return DB::transaction(function () use ($proposal): ?ProposalAssignment {
            /** @var ProposalDistributionState $state */
            $state = ProposalDistributionState::query()
                ->lockForUpdate()
                ->findOrFail(1);

            /** @var Collection<int, ProposalRepresentative> $representatives */
            $representatives = ProposalRepresentative::query()
                ->where('is_active', true)
                ->orderBy('queue_position')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($representatives->isEmpty()) {
                return null;
            }

            $nextRepresentative = $this->resolveNextRepresentative(
                $representatives,
                $state->last_representative_id,
            );

            $sequence = $state->last_sequence + 1;

            $proposal->forceFill([
                'assigned_representative_id' => $nextRepresentative->id,
                'distribution_sequence' => $sequence,
                'distributed_at' => now(),
            ])->save();

            $assignment = $proposal->assignments()->create([
                'representative_id' => $nextRepresentative->id,
                'sequence' => $sequence,
                'strategy' => 'round_robin',
                'assigned_at' => now(),
            ]);

            $state->forceFill([
                'last_representative_id' => $nextRepresentative->id,
                'last_sequence' => $sequence,
            ])->save();

            return $assignment->load('representative');
        });
    }

    /**
     * @param  Collection<int, ProposalRepresentative>  $representatives
     */
    protected function resolveNextRepresentative(
        Collection $representatives,
        ?int $lastRepresentativeId,
    ): ProposalRepresentative {
        if ($representatives->count() === 1) {
            return $representatives->first();
        }

        $lastIndex = $representatives->search(
            fn (ProposalRepresentative $representative): bool => $representative->id === $lastRepresentativeId,
        );

        if ($lastIndex === false) {
            return $representatives->first();
        }

        $nextIndex = ($lastIndex + 1) % $representatives->count();

        return $representatives->values()->get($nextIndex);
    }
}
