<?php

namespace App\Support\ActivityLog;

use Carbon\CarbonInterface;
use Illuminate\Support\Str;

final class ActivityTimelineItem
{
    /**
     * @param  array<int, ActivityChange>  $changes
     * @param  array<int, array{label: string, value: string}>  $technicalDetails
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $title,
        public string $icon,
        public string $color,
        public string $author,
        public ?string $authorAvatarUrl,
        public CarbonInterface $occurredAt,
        public array $changes,
        public array $technicalDetails,
        public string $technicalDetailsAsText,
        public ?string $eventType = null,
        public ?string $eventLabel = null,
        public ?string $rawJson = null,
        public array $metadata = [],
    ) {}

    public function authorInitials(): string
    {
        if ($this->isSystem()) {
            return 'SI';
        }

        return Str::of($this->author)
            ->explode(' ')
            ->filter()
            ->map(fn (string $part): string => Str::upper(Str::substr($part, 0, 1)))
            ->take(2)
            ->implode('');
    }

    public function isSystem(): bool
    {
        return in_array($this->author, ['Sistema', 'Sistema (Automático)', 'system', 'Sistema / Automático'], true);
    }

    public function hasChanges(): bool
    {
        return count($this->changes) > 0;
    }

    public function statusChange(): ?ActivityChange
    {
        foreach ($this->changes as $change) {
            if ($change->key === 'status') {
                return $change;
            }
        }

        return null;
    }

    /**
     * @return array<int, ActivityChange>
     */
    public function observationChanges(): array
    {
        return array_values(array_filter(
            $this->changes,
            fn (ActivityChange $change): bool => $change->isLongText,
        ));
    }

    /**
     * @return array<int, ActivityChange>
     */
    public function regularChanges(): array
    {
        return array_values(array_filter(
            $this->changes,
            fn (ActivityChange $change): bool => $change->key !== 'status' && ! $change->isLongText,
        ));
    }

    public function hasInternalNote(): bool
    {
        foreach ($this->changes as $change) {
            if ($change->isInternal) {
                return true;
            }
        }

        return false;
    }
}
