<?php

namespace App\Filament\Resources\ResponsibilityDelegations\Schemas;

use App\Enums\MeasurementResponsibility;
use App\Enums\OperationStatus;
use App\Models\Operation;
use App\Models\ResponsibilityDelegation;
use App\Support\BusinessTime;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class ResponsibilityDelegationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // A delegação é sempre um vínculo novo -- o formulário desabilita
                // os dois campos ao editar --, então aqui não há valor histórico
                // a preservar: só candidatos elegíveis. O
                // `validateBusinessRules()` do serviço continua recusando
                // inativo e não provisionado, e é ele quem garante a integridade
                // contra payload manipulado; o filtro aqui é só para não
                // oferecer quem seria recusado.
                Select::make('delegator_user_id')
                    ->label('Delegante')
                    ->relationship('delegator', 'name', fn (Builder $query): Builder => $query->operational())
                    ->searchable()
                    ->preload()
                    ->required()
                    ->disabled(fn (?ResponsibilityDelegation $record) => $record !== null
                        || (! auth()->user()?->hasAnyRole(['super-admin', 'admin']) && ! auth()->user()?->can('delegations.manage')))
                    ->dehydrated()
                    ->default(fn () => auth()->id()),

                Select::make('delegate_user_id')
                    ->label('Delegado')
                    ->relationship('delegate', 'name', fn (Builder $query): Builder => $query->operational())
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
                    // Escopo novo só em operação em andamento; a operação de uma
                    // delegação já registrada continua listada para que o campo
                    // -- desabilitado na edição -- ainda saiba se renderizar.
                    ->options(function (?ResponsibilityDelegation $record): array {
                        $user = auth()->user();

                        if ($user === null) {
                            return [];
                        }

                        return Operation::query()
                            ->visibleTo($user)
                            ->where(fn (Builder $eligible): Builder => $eligible
                                ->where('operations.status', OperationStatus::Active->value)
                                ->when(
                                    filled($record?->scope_operation_id),
                                    fn (Builder $existing): Builder => $existing
                                        ->orWhere('operations.id', $record->scope_operation_id),
                                ))
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

                // A vigência é operada no fuso do negócio; a persistência segue em UTC.
                DateTimePicker::make('starts_at')
                    ->label('Início')
                    ->timezone(BusinessTime::timezone())
                    ->required()
                    ->seconds(false)
                    ->default(now())
                    ->disabled(fn (?ResponsibilityDelegation $record) => $record !== null),

                DateTimePicker::make('ends_at')
                    ->label('Término')
                    ->timezone(BusinessTime::timezone())
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
