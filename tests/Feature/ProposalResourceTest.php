<?php

use App\Filament\Resources\Proposals\ProposalResource;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Placeholder;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
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

it('separates the proposal summary into proponent content and a discreet internal layer', function () {
    $livewire = makeSchemaTestLivewire();
    $schema = ProposalResource::infolist(Schema::make($livewire));

    $section = collect(flattenSchemaComponents($schema))
        ->first(fn (Component $component): bool => $component instanceof Section
            && $component->getHeading() === 'Resumo da Proposta');

    expect($section)->not->toBeNull()
        ->and($section->getExtraAttributes()['class'] ?? null)->toContain('bsi-proposal-summary');

    $entries = collect($section->getChildSchemas(withHidden: true))
        ->flatMap(fn (Schema $childSchema): array => $childSchema->getComponents())
        ->filter(fn (mixed $component): bool => $component instanceof TextEntry)
        ->mapWithKeys(fn (TextEntry $entry): array => [$entry->getName() => $entry]);

    expect($entries->keys()->all())->toBe(['observations', 'internal_notes']);

    $observations = $entries['observations'];

    expect($observations->getLabel())->toBe('Informações Complementares do Proponente')
        ->and($observations->getPlaceholder())->toBe('Nenhuma observação informada pelo proponente.')
        ->and($observations->getExtraAttributes()['class'] ?? null)->toContain('bsi-prose-block');

    $internal = $entries['internal_notes'];

    expect($internal->getLabel())->toBe('Parecer Técnico / Comercial Interno')
        ->and($internal->getPlaceholder())->toBe('Sem observações internas registradas.')
        ->and($internal->getHint())->toBe('Uso interno')
        ->and($internal->getHintIcon())->toBe('heroicon-m-lock-closed')
        ->and($internal->getExtraAttributes()['class'] ?? null)->toContain('bsi-internal-note');
});

it('places the proponent on its own row above a proportional second row', function () {
    $livewire = makeSchemaTestLivewire();
    $schema = ProposalResource::infolist(Schema::make($livewire));

    $execSection = collect(flattenSchemaComponents($schema))
        ->first(fn (Component $component): bool => $component instanceof Section
            && $component->getHeading() === 'Resumo Executivo da Proposta');

    expect($execSection)->not->toBeNull();

    $grid = collect($execSection->getChildSchemas(withHidden: true))
        ->flatMap(fn (Schema $childSchema): array => $childSchema->getComponents())
        ->first(fn (mixed $component): bool => $component instanceof Grid);

    expect($grid)->not->toBeNull()
        ->and($grid->getColumns('xl'))->toBe(12);

    $entries = collect($grid->getChildSchemas(withHidden: true))
        ->flatMap(fn (Schema $childSchema): array => $childSchema->getComponents())
        ->filter(fn (mixed $component): bool => $component instanceof TextEntry);

    expect($entries->first(fn (TextEntry $entry): bool => $entry->getName() === 'company.name')->getColumnSpan('default'))->toBe('full');

    $spans = $entries
        ->reject(fn (TextEntry $entry): bool => $entry->getName() === 'company.name')
        ->mapWithKeys(fn (TextEntry $entry): array => [$entry->getName() => $entry->getColumnSpan('xl')]);

    expect($spans->all())->toBe([
        'total_requested_amount' => 2,
        'status' => 2,
        'representative.name' => 3,
        'time_in_status' => 2,
        'next_action' => 3,
    ]);
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
