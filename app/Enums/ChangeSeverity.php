<?php

namespace App\Enums;

/**
 * How much scrutiny a single field change deserves during a reconciliation.
 *
 * The distinction is the whole point of comparing instead of overwriting: a
 * payment landing on an installment is routine, a due date moving is a change to
 * the financial schedule, and a contract changing buyer is not an update at all.
 */
enum ChangeSeverity: string
{
    /** Expected month-to-month movement. Confirmed with everything else. */
    case Normal = 'normal';

    /**
     * Legitimate but consequential -- a retificação. Shown apart, confirmed
     * deliberately, never applied without someone having seen it.
     */
    case Critical = 'critica';

    /**
     * Cannot be applied by an import at all. The spreadsheet is describing
     * something the domain expresses another way (a resale is a new contract,
     * not an edited one), so the row is refused and a person decides.
     */
    case Blocked = 'bloqueada';
}
