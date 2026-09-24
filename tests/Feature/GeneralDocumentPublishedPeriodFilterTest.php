<?php

use App\Filament\Resources\Nimbus\GeneralDocuments\Pages\ListGeneralDocuments;
use App\Models\Nimbus\DocumentCategory;
use App\Models\Nimbus\GeneralDocument;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Forms\Components\DatePicker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);

    $user = User::factory()->create();
    $user->assignRole('super-admin');

    $this->actingAs($user);

    $this->category = DocumentCategory::query()->create(['name' => 'Institucional']);
});

function createPublishedDocument(DocumentCategory $category, string $title, ?string $publishedAt, bool $isActive = true): GeneralDocument
{
    return GeneralDocument::query()->create([
        'nimbus_category_id' => $category->id,
        'title' => $title,
        'file_path' => 'nimbus/general-documents/'.str($title)->slug().'.pdf',
        'file_original_name' => str($title)->slug().'.pdf',
        'file_size' => 1024,
        'file_mime' => 'application/pdf',
        'is_active' => $isActive,
        'published_at' => $publishedAt,
    ]);
}

function seedPublishedPeriodDocuments(DocumentCategory $category): array
{
    return [
        'before' => createPublishedDocument($category, 'Documento de agosto', '2026-08-31 10:00:00'),
        'start' => createPublishedDocument($category, 'Documento do primeiro dia', '2026-09-01 00:00:00'),
        'middle' => createPublishedDocument($category, 'Documento do meio do mes', '2026-09-15 14:00:00'),
        'end' => createPublishedDocument($category, 'Documento do ultimo dia', '2026-09-30 23:30:00'),
        'after' => createPublishedDocument($category, 'Documento de outubro', '2026-10-01 00:00:01'),
        'null' => createPublishedDocument($category, 'Documento sem publicacao', null),
    ];
}

it('shows every document when the published period filter is empty', function (): void {
    $documents = seedPublishedPeriodDocuments($this->category);

    Livewire::test(ListGeneralDocuments::class)
        ->assertCountTableRecords(6)
        ->assertCanSeeTableRecords(array_values($documents));
});

it('filters by published from only, including the start day', function (): void {
    $documents = seedPublishedPeriodDocuments($this->category);

    Livewire::test(ListGeneralDocuments::class)
        ->set('tableDeferredFilters.published_at.published_from', '2026-09-01')
        ->assertCountTableRecords(6)
        ->call('applyTableFilters')
        ->assertCountTableRecords(4)
        ->assertCanSeeTableRecords([$documents['start'], $documents['middle'], $documents['end'], $documents['after']])
        ->assertCanNotSeeTableRecords([$documents['before'], $documents['null']]);
});

it('filters by published until only, including the whole final day', function (): void {
    $documents = seedPublishedPeriodDocuments($this->category);

    Livewire::test(ListGeneralDocuments::class)
        ->set('tableDeferredFilters.published_at.published_until', '2026-09-30')
        ->assertCountTableRecords(6)
        ->call('applyTableFilters')
        ->assertCountTableRecords(4)
        ->assertCanSeeTableRecords([$documents['before'], $documents['start'], $documents['middle'], $documents['end']])
        ->assertCanNotSeeTableRecords([$documents['after'], $documents['null']]);
});

it('filters by an inclusive published period', function (): void {
    $documents = seedPublishedPeriodDocuments($this->category);

    Livewire::test(ListGeneralDocuments::class)
        ->set('tableDeferredFilters.published_at.published_from', '2026-09-01')
        ->set('tableDeferredFilters.published_at.published_until', '2026-09-30')
        ->call('applyTableFilters')
        ->assertCountTableRecords(3)
        ->assertCanSeeTableRecords([$documents['start'], $documents['middle'], $documents['end']])
        ->assertCanNotSeeTableRecords([$documents['before'], $documents['after'], $documents['null']]);
});

it('accepts a single-day published period', function (): void {
    $documents = seedPublishedPeriodDocuments($this->category);

    Livewire::test(ListGeneralDocuments::class)
        ->set('tableDeferredFilters.published_at.published_from', '2026-09-15')
        ->set('tableDeferredFilters.published_at.published_until', '2026-09-15')
        ->call('applyTableFilters')
        ->assertCountTableRecords(1)
        ->assertCanSeeTableRecords([$documents['middle']]);
});

it('keeps each published date field in its own state', function (): void {
    seedPublishedPeriodDocuments($this->category);

    Livewire::test(ListGeneralDocuments::class)
        ->set('tableDeferredFilters.published_at.published_from', '2026-09-01')
        ->assertSet('tableDeferredFilters.published_at.published_from', '2026-09-01')
        ->assertSet('tableDeferredFilters.published_at.published_until', null)
        ->set('tableDeferredFilters.published_at.published_until', '2026-09-30')
        ->assertSet('tableDeferredFilters.published_at.published_from', '2026-09-01')
        ->assertSet('tableDeferredFilters.published_at.published_until', '2026-09-30')
        ->call('applyTableFilters')
        ->assertSet('tableFilters.published_at.published_from', '2026-09-01')
        ->assertSet('tableFilters.published_at.published_until', '2026-09-30');
});

it('clears both published dates when filters are reset', function (): void {
    seedPublishedPeriodDocuments($this->category);

    Livewire::test(ListGeneralDocuments::class)
        ->set('tableDeferredFilters.published_at.published_from', '2026-09-01')
        ->set('tableDeferredFilters.published_at.published_until', '2026-09-30')
        ->call('applyTableFilters')
        ->assertCountTableRecords(3)
        ->call('removeTableFilter', 'published_at')
        ->assertSet('tableFilters.published_at.published_from', null)
        ->assertSet('tableFilters.published_at.published_until', null)
        ->assertCountTableRecords(6)
        ->set('tableDeferredFilters.published_at.published_from', '2026-09-15')
        ->set('tableDeferredFilters.published_at.published_until', '2026-09-15')
        ->call('applyTableFilters')
        ->assertCountTableRecords(1)
        ->resetTableFilters()
        ->assertCountTableRecords(6);
});

it('clears both published dates through the runtime clear-all path', function (): void {
    seedPublishedPeriodDocuments($this->category);

    Livewire::test(ListGeneralDocuments::class)
        ->set('tableDeferredFilters.published_at.published_from', '2026-09-01')
        ->set('tableDeferredFilters.published_at.published_until', '2026-09-30')
        ->call('applyTableFilters')
        ->assertCountTableRecords(3)
        ->call('removeTableFilters')
        ->assertSet('tableFilters.published_at.published_from', null)
        ->assertSet('tableFilters.published_at.published_until', null)
        ->assertCountTableRecords(6);
});

it('returns no records for an inverted published period', function (): void {
    seedPublishedPeriodDocuments($this->category);

    Livewire::test(ListGeneralDocuments::class)
        ->set('tableDeferredFilters.published_at.published_from', '2026-09-30')
        ->set('tableDeferredFilters.published_at.published_until', '2026-09-01')
        ->call('applyTableFilters')
        ->assertCountTableRecords(0)
        ->assertSee('Limpar filtros')
        ->call('removeTableFilters')
        ->assertCountTableRecords(6);
});

it('removes a single published date through its own indicator', function (): void {
    $documents = seedPublishedPeriodDocuments($this->category);

    Livewire::test(ListGeneralDocuments::class)
        ->set('tableDeferredFilters.published_at.published_from', '2026-09-01')
        ->set('tableDeferredFilters.published_at.published_until', '2026-09-30')
        ->call('applyTableFilters')
        ->assertSee('Publicado desde 01/09/2026')
        ->assertSee('Publicado até 30/09/2026')
        ->call('removeTableFilter', 'published_at', 'published_from')
        ->assertSet('tableFilters.published_at.published_from', null)
        ->assertSet('tableFilters.published_at.published_until', '2026-09-30')
        ->assertCountTableRecords(4)
        ->assertCanSeeTableRecords([$documents['before'], $documents['start'], $documents['middle'], $documents['end']]);
});

it('configures both published fields as Brazilian JS date pickers', function (): void {
    $filter = Livewire::test(ListGeneralDocuments::class)
        ->instance()->getTable()->getFilter('published_at');

    $fields = $filter->getSchemaComponents();

    expect($fields)->toHaveCount(2);

    foreach ($fields as $field) {
        expect($field)->toBeInstanceOf(DatePicker::class)
            ->and($field->isNative())->toBeFalse()
            ->and($field->getDisplayFormat())->toBe('d/m/Y')
            ->and($field->getPlaceholder())->toBe('dd/mm/aaaa');
    }

    expect($fields[0]->getName())->toBe('published_from')
        ->and($fields[0]->getLabel())->toBe('Publicado a partir de')
        ->and($fields[1]->getName())->toBe('published_until')
        ->and($fields[1]->getLabel())->toBe('Publicado até');
});

it('combines the published period with other filters and search', function (): void {
    $documents = seedPublishedPeriodDocuments($this->category);

    $archived = createPublishedDocument($this->category, 'Regulamento arquivado de setembro', '2026-09-10 09:00:00', false);

    Livewire::test(ListGeneralDocuments::class)
        ->set('tableDeferredFilters.published_at.published_from', '2026-09-01')
        ->set('tableDeferredFilters.published_at.published_until', '2026-09-30')
        ->set('tableDeferredFilters.is_active.value', '0')
        ->call('applyTableFilters')
        ->assertCountTableRecords(1)
        ->assertCanSeeTableRecords([$archived])
        ->assertCanNotSeeTableRecords([$documents['middle']])
        ->searchTable('Regulamento arquivado')
        ->assertCountTableRecords(1)
        ->searchTable('Documento do meio')
        ->assertCountTableRecords(0)
        ->searchTable('')
        ->assertCountTableRecords(1);
});
