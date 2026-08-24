<?php

namespace App\Filament\Resources\Emissions\Pages;

use App\Actions\Emissions\ConsolidateInitialSalesBoards;
use App\Actions\Emissions\CreateInitialConstructions;
use App\Filament\Resources\Emissions\EmissionResource;
use App\Filament\Resources\Emissions\Schemas\EmissionConstructionsStep;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;

class CreateEmission extends CreateRecord
{
    protected static string $resource = EmissionResource::class;

    protected static ?string $title = 'Cadastrar Emissão';

    protected static ?string $breadcrumb = 'Cadastrar';

    protected Width|string|null $maxContentWidth = Width::Full;

    /**
     * The emission, its constructions and their initial sales boards are a
     * single unit: if any of them fails, none of them is persisted.
     */
    protected ?bool $hasDatabaseTransactions = true;

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page',
    ];

    /**
     * Constructions captured by the wizard, held between the form submission
     * and the moment the emission is persisted.
     *
     * @var array<int, array<string, mixed>>
     */
    protected array $initialConstructions = [];

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Emissão cadastrada com sucesso.';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->initialConstructions = array_values((array) ($data[EmissionConstructionsStep::STATE_PATH] ?? []));

        unset($data[EmissionConstructionsStep::STATE_PATH]);

        return $data;
    }

    /**
     * Runs inside the page transaction, so any failure here rolls the emission
     * back instead of leaving it without constructions.
     */
    protected function afterCreate(): void
    {
        $emission = $this->getRecord();

        app(CreateInitialConstructions::class)->handle($emission, $this->initialConstructions);

        // An emission created straight into a non-draft status has no
        // elaboration phase left, so its position is consolidated right away.
        if (! $emission->isInDraft()) {
            app(ConsolidateInitialSalesBoards::class)->handle($emission);
        }
    }
}
