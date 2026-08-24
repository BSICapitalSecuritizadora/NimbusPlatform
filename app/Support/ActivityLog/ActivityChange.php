<?php

namespace App\Support\ActivityLog;

final class ActivityChange
{
    public function __construct(
        public string $key,
        public string $label,
        public ?string $old = null,
        public ?string $new = null,
        public ?string $oldColor = null,
        public ?string $newColor = null,
        public bool $isLongText = false,
        public bool $isInternal = false,
    ) {}

    public function hasTransition(): bool
    {
        return $this->old !== null
            && $this->new !== null
            && $this->old !== $this->new;
    }
}
