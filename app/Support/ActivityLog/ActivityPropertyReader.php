<?php

namespace App\Support\ActivityLog;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

final class ActivityPropertyReader
{
    /** @var array<string, mixed> */
    private array $properties;

    /** @var list<string> */
    private array $issues = [];

    /** @param array<string, mixed>|Collection<string, mixed>|null $properties */
    public function __construct(array|Collection|null $properties)
    {
        $this->properties = $properties instanceof Collection
            ? $properties->all()
            : ($properties ?? []);
    }

    public function has(string $key): bool
    {
        return Arr::has($this->properties, $key);
    }

    public function nullableInt(string $key): ?int
    {
        $value = Arr::get($this->properties, $key);

        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }

        $this->recordInvalidShape($key);

        return null;
    }

    public function nullableBool(string $key): ?bool
    {
        $value = Arr::get($this->properties, $key);

        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if ($value === 0 || $value === 1 || $value === '0' || $value === '1') {
            return (bool) $value;
        }

        $this->recordInvalidShape($key);

        return null;
    }

    public function nullableString(string $key): ?string
    {
        $value = Arr::get($this->properties, $key);

        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        $this->recordInvalidShape($key);

        return null;
    }

    public function nullableDecimal(string $key): ?string
    {
        $value = Arr::get($this->properties, $key);

        if ($value === null) {
            return null;
        }

        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            return (string) $value;
        }

        $this->recordInvalidShape($key);

        return null;
    }

    /** @return list<int> */
    public function intList(string $key): array
    {
        $value = Arr::get($this->properties, $key);

        if ($value === null) {
            return [];
        }

        if ($value instanceof Collection) {
            $value = $value->all();
        }

        if (! is_array($value)) {
            $this->recordInvalidShape($key);

            return [];
        }

        $ids = [];

        foreach ($value as $index => $candidate) {
            if (is_int($candidate) || (is_string($candidate) && preg_match('/^\d+$/', $candidate) === 1)) {
                $ids[] = (int) $candidate;

                continue;
            }

            $this->recordInvalidShape($key.'.'.$index);
        }

        return array_values(array_unique($ids));
    }

    public function nested(string $key): self
    {
        $value = Arr::get($this->properties, $key);

        if ($value === null) {
            return new self(null);
        }

        if ($value instanceof Collection || is_array($value)) {
            return new self($value);
        }

        $this->recordInvalidShape($key);

        return new self(null);
    }

    /** @return list<string> */
    public function issues(): array
    {
        return $this->issues;
    }

    private function recordInvalidShape(string $key): void
    {
        $this->issues[] = 'invalid_property_shape:'.$key;
        $this->issues = array_values(array_unique($this->issues));
    }
}
