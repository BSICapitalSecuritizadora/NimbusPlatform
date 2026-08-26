<?php

namespace App\Actions\Contracts;

/**
 * What a spreadsheet does to the set of buyers of a contract already on record.
 *
 * Kept apart from {@see ContractReconciler} on purpose: every field that one
 * compares is a scalar that either matches or does not, while a buyer set is
 * compared as a set -- order carries no meaning, and the answer is not "changed
 * or not" but which buyers arrived and which left.
 *
 * The two severities are not symmetrical, and deliberately so:
 *
 * - adding a buyer is what this whole evolution exists to allow. It widens the
 *   contract, loses nothing, and the operator confirms it on the conference
 *   screen: CRITICAL.
 * - removing one is refused. A monthly position that stopped listing a document
 *   is far more likely to be an incomplete file than a legal change of parties,
 *   and dropping a buyer from a contract is not something a spreadsheet should
 *   be able to do by omission: CONFLICT. The removal remains possible by hand,
 *   deliberately, through the form.
 *
 * A replacement is both at once, so it is refused for the same reason.
 */
class ContractBuyerReconciler
{
    /**
     * @param  list<int>  $current  buyer ids the contract holds today
     * @param  list<int>  $imported  buyer ids the file brings for it
     */
    public function compare(array $current, array $imported): ContractBuyerComparison
    {
        $currentSet = $this->normalize($current);
        $importedSet = $this->normalize($imported);

        return new ContractBuyerComparison(
            current: $currentSet,
            imported: $importedSet,
            added: array_values(array_diff($importedSet, $currentSet)),
            removed: array_values(array_diff($currentSet, $importedSet)),
        );
    }

    /**
     * A set: unique, and in a fixed order so two files that list the same buyers
     * in different order compare equal.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function normalize(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        sort($ids);

        return $ids;
    }
}
