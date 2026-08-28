<?php

namespace App\Enums;

/**
 * What actually authorized an actor to act over a measurement responsibility.
 *
 * Holding the `admin` or `super-admin` role is not a source: an administrator
 * who is also the direct responsible acts as the responsible, and one who holds
 * an effective delegation acts as the delegate. `AdminOverride` is reserved for
 * the case where the administrative bypass is the only thing that authorized the
 * action -- which is exactly what an audit needs to be able to tell apart.
 */
enum WorkflowAuthorizationSource: string
{
    case Direct = 'direct';
    case Delegated = 'delegated';
    case AdminOverride = 'admin_override';
    case None = 'none';

    public function authorizes(): bool
    {
        return $this !== self::None;
    }
}
