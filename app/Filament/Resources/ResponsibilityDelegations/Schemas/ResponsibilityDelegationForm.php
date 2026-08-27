<?php

namespace App\Filament\Resources\ResponsibilityDelegations\Schemas;

use App\Enums\MeasurementResponsibility;
use App\Models\Operation;
use App\Models\ResponsibilityDelegation;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class ResponsibilityDelegationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('delegator_user_id')
                    ->label('Delegante')
                    ->relationship('delegator', 'name')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->disabled(fn (?ResponsibilityDelegation $record) => $record !== null
                        || (! auth()->user()?->hasAnyRole(['super-admin', 'admin']) && ! auth()->user()?->can('delegations.manage')))
                    ->dehydrated()
                    ->default(fn () => auth()->id()),

                Select::make('delegate_user_id')
                    ->label('Delegado')
                    ->relationship('delegate', 'name')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->disabled(fn (?ResponsibilityDelegation $record) => $record !== null),

                Select::make('scope_type')
                    ->label('Escopo')
                    ->options(ResponsibilityDelegation::SCOPE_OPTIONS)
                    ->required()
                    ->live()
                    ->disabled(fn (?ResponsibilityDelegation $record) => $record !== null),

                Select::make('scope_operation_id')
                    ->label('Operação')
                    ->options(function (): array {
                        $user = auth()->user();

                        if ($user === null) {
                            return [];
                        }

                        return Operation::query()
                            ->visibleTo($user)
                            ->orderBy('code')
                            ->get(['id', 'code', 'title'])
                            ->mapWithKeys(fn (Operation $operation): array => [
                                $operation->getKey() => $operation->code.' — '.$operation->title,
                            ])
                            ->all();
                    })
                    ->searchable()
                    ->preload()
                    ->visible(fn ($get) => in_array($get('scope_type'), ['operation', 'stage'], true))
                    ->required(fn ($get) => $get('scope_type') === 'operation')
                    ->disabled(fn (?ResponsibilityDelegation $record) => $record !== null),

                Select::make('scope_stage')
                    ->label('Etapa')
                    ->options(ResponsibilityDelegation::STAGE_OPTIONS)
                    ->live()
                    ->visible(fn ($get) => $get('scope_type') === 'stage')
                    ->required(fn ($get) => $get('scope_type') === 'stage')
                    ->disabled(fn (?ResponsibilityDelegation $record) => $record !== null),

                Select::make('scope_responsibility')
                    ->label('Responsabilidade')
                    ->options(fn ($get): array => MeasurementResponsibility::options((int) $get('scope_stage')))
                    ->visible(fn ($get): bool => $get('scope_type') === ResponsibilityDelegation::SCOPE_STAGE)
                    ->required(fn ($get): bool => $get('scope_type') === ResponsibilityDelegation::SCOPE_STAGE)
                    ->disabled(fn (?ResponsibilityDelegation $record) => $record !== null),

                DateTimePicker::make('starts_at')
                    ->label('Início')
                    ->required()
                    ->seconds(false)
                    ->default(now())
                    ->disabled(fn (?ResponsibilityDelegation $record) => $record !== null),

                DateTimePicker::make('ends_at')
                    ->label('Término')
                    ->required()
                    ->seconds(false)
                    ->after('starts_at')
                    ->disabled(fn (?ResponsibilityDelegation $record) => $record !== null),

                Textarea::make('reason')
                    ->label('Motivo')
                    ->required()
                    ->rows(3)
                    ->maxLength(1000)
                    ->disabled(fn (?ResponsibilityDelegation $record) => $record !== null),
            ]);
    }
}
