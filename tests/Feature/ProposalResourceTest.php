<?php

use App\Filament\Resources\Proposals\ProposalResource;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Placeholder;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Contracts\TranslatableContentDriver;
use Livewire\Component as LivewireComponent;

it('separates proposal editing fields from the read-only infolist', function () {
    $livewire = makeSchemaTestLivewire();

    $formSchema = ProposalResource::form(Schema::make($livewire));
    $infolistSchema = ProposalResource::infolist(Schema::make($livewire));

    $formComponents = collect(flattenSchemaComponents($formSchema));
    $infolistComponents = collect(flattenSchemaComponents($infolistSchema));

    $formFieldNames = $formComponents
        ->filter(fn (mixed $component): bool => $component instanceof Field)
        ->map(fn (Field $component): string => $component->getName())
        ->values()
        ->all();

    $infolistEntryNames = $infolistComponents
        ->filter(fn (mixed $component): bool => $component instanceof TextEntry)
        ->map(fn (TextEntry $component): string => $component->getName())
        ->values()
        ->all();

    expect($formComponents->contains(fn (mixed $component): bool => $component instanceof Placeholder))->toBeFalse()
        ->and($formFieldNames)->toBe(['internal_notes'])
        ->and($infolistEntryNames)->toContain(
            'distribution_sequence',
            'representative.name',
            'status',
            'latestContinuationAccess.status_label',
            'company.name',
            'contact.email',
            'observations',
        );
});

/**
 * @return array<int, Component>
 */
function flattenSchemaComponents(Schema $schema): array
{
    $components = [];

    foreach ($schema->getComponents() as $component) {
        if (! $component instanceof Component) {
            continue;
        }

        $components[] = $component;

        foreach ($component->getChildSchemas(withHidden: true) as $childSchema) {
            $components = [
                ...$components,
                ...flattenSchemaComponents($childSchema),
            ];
        }
    }

    return $components;
}

function makeSchemaTestLivewire(): LivewireComponent&HasSchemas
{
    return new class extends LivewireComponent implements HasSchemas
    {
        public function __construct()
        {
            $this->setId('proposal-resource-test');
            $this->setName('proposal-resource-test');
        }

        public function makeFilamentTranslatableContentDriver(): ?TranslatableContentDriver
        {
            return null;
        }

        public function getOldSchemaState(string $statePath): mixed
        {
            return null;
        }

        public function getSchemaComponent(string $key, bool $withHidden = false, array $skipComponentsChildContainersWhileSearching = []): Component|Action|ActionGroup|null
        {
            return null;
        }

        public function getSchema(string $name): ?Schema
        {
            return null;
        }

        public function currentlyValidatingSchema(?Schema $schema): void {}

        public function getDefaultTestingSchemaName(): ?string
        {
            return null;
        }
    };
}
