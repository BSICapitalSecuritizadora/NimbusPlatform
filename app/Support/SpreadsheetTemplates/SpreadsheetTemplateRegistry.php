<?php

namespace App\Support\SpreadsheetTemplates;

use Illuminate\Support\Collection;

/**
 * Central registry of every spreadsheet template managed by NimbusPlatform.
 *
 * Definitions are registered at boot time (see SpreadsheetTemplateServiceProvider).
 * The settings page and any other consumer read from here, so registering a
 * new definition is enough for the template to show up everywhere.
 */
class SpreadsheetTemplateRegistry
{
    /**
     * @var array<string, SpreadsheetTemplateDefinition>
     */
    protected array $definitions = [];

    public function register(SpreadsheetTemplateDefinition $definition): void
    {
        $this->definitions[$definition->key] = $definition;
    }

    public function find(string $key): ?SpreadsheetTemplateDefinition
    {
        return $this->definitions[$key] ?? null;
    }

    /**
     * @return Collection<int, SpreadsheetTemplateDefinition>
     */
    public function all(): Collection
    {
        return collect(array_values($this->definitions));
    }

    /**
     * Categories in first-seen (registration) order.
     *
     * @return list<string>
     */
    public function categories(): array
    {
        return $this->all()
            ->map(fn (SpreadsheetTemplateDefinition $definition): string => $definition->category)
            ->unique()
            ->values()
            ->all();
    }
}
