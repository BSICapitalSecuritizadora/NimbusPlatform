<?php

namespace App\Support\Delegations;

use App\Enums\WorkflowAuthorizationSource;
use App\Models\ResponsibilityDelegation;

/**
 * One resolution of "may this actor act over this responsibility, and on whose
 * authority" -- decided once and used both to allow the action and to describe
 * it in the audit trail, so the two can never disagree.
 */
final class ResponsibilityAuthorization
{
    private function __construct(
        public readonly WorkflowAuthorizationSource $source,
        public readonly ?ResponsibilityDelegation $delegation,
    ) {}

    public static function direct(): self
    {
        return new self(WorkflowAuthorizationSource::Direct, null);
    }

    public static function delegated(ResponsibilityDelegation $delegation): self
    {
        return new self(WorkflowAuthorizationSource::Delegated, $delegation);
    }

    public static function adminOverride(): self
    {
        return new self(WorkflowAuthorizationSource::AdminOverride, null);
    }

    public static function none(): self
    {
        return new self(WorkflowAuthorizationSource::None, null);
    }

    public function authorizes(): bool
    {
        return $this->source->authorizes();
    }

    public function isDelegated(): bool
    {
        return $this->source === WorkflowAuthorizationSource::Delegated;
    }

    public function isAdminOverride(): bool
    {
        return $this->source === WorkflowAuthorizationSource::AdminOverride;
    }
}
