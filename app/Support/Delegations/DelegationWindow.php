<?php

namespace App\Support\Delegations;

use App\Models\ResponsibilityDelegation;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The stretch of time a delegation can be in force: `[starts_at, ends_at]`.
 *
 * Closed on both ends, which is the same reading
 * {@see ResponsibilityDelegation::scopeActive()} and
 * {@see ResponsibilityDelegation::isActive()} already apply -- a delegation is
 * in force at the exact instant it starts and at the exact instant it ends. Two
 * windows can therefore coexist when each starts no later than the other ends;
 * vigência, ciclo e overlap não podem discordar sobre isso.
 *
 * A revoked delegation has no window at all: it can no longer be in force, so
 * it can neither close a cycle nor conflict with anything.
 */
final class DelegationWindow
{
    private function __construct(
        public readonly CarbonImmutable $startsAt,
        public readonly CarbonImmutable $endsAt,
    ) {}

    public static function between(
        DateTimeInterface|string|null $startsAt,
        DateTimeInterface|string|null $endsAt,
    ): ?self {
        if ($startsAt === null || $endsAt === null) {
            return null;
        }

        $start = CarbonImmutable::parse($startsAt);
        $end = CarbonImmutable::parse($endsAt);

        return $start->lessThanOrEqualTo($end) ? new self($start, $end) : null;
    }

    public static function fromDelegation(ResponsibilityDelegation $delegation): ?self
    {
        if ($delegation->revoked_at !== null) {
            return null;
        }

        return self::between($delegation->starts_at, $delegation->ends_at);
    }

    /**
     * When the two could be in force at the same time, or null when they never can.
     */
    public function intersect(self $other): ?self
    {
        $startsAt = $this->startsAt->greaterThan($other->startsAt) ? $this->startsAt : $other->startsAt;
        $endsAt = $this->endsAt->lessThan($other->endsAt) ? $this->endsAt : $other->endsAt;

        return $startsAt->lessThanOrEqualTo($endsAt) ? new self($startsAt, $endsAt) : null;
    }

    /**
     * The part of the window that has not already passed. What is left of an
     * expired window is nothing, which is why an expired edge takes no part in a
     * cycle that can only be closed from here on.
     */
    public function notBefore(CarbonImmutable $instant): ?self
    {
        if ($this->endsAt->lessThan($instant)) {
            return null;
        }

        return new self(
            $this->startsAt->greaterThan($instant) ? $this->startsAt : $instant,
            $this->endsAt,
        );
    }

    /**
     * Stable identity of the accumulated window, so a graph walk can tell a
     * state it has already explored from one it has not.
     */
    public function signature(): string
    {
        return $this->startsAt->getTimestamp().':'.$this->endsAt->getTimestamp();
    }
}
