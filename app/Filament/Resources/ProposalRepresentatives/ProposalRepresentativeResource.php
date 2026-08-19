<?php

namespace App\Filament\Resources\ProposalRepresentatives;

use App\Filament\Resources\ProposalRepresentatives\Pages\CreateProposalRepresentative;
use App\Filament\Resources\ProposalRepresentatives\Pages\EditProposalRepresentative;
use App\Filament\Resources\ProposalRepresentatives\Pages\ListProposalRepresentatives;
use App\Filament\Resources\Proposals\ProposalResource;
use App\Models\ProposalRepresentative;
use App\Models\User;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ProposalRepresentativeResource extends Resource
{
    protected static ?string $model = ProposalRepresentative::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|\UnitEnum|null $navigationGroup = 'Comercial';

    protected static ?string $navigationParentItem = 'Propostas';

    protected static ?string $navigationLabel = 'Representantes Comerciais';

    protected static ?string $modelLabel = 'Representante Comercial';

    protected static ?string $pluralModelLabel = 'Representantes Comerciais';

    protected static ?int $navigationSort = 11;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Nome Completo')->required()->maxLength(255),
            TextInput::make('email')->label('E-mail')->email()->required()->maxLength(255),
            Select::make('user_id')
                ->label('Usuário Interno')
                ->relationship('user', 'email')
                ->getOptionLabelFromRecordUsing(fn (User $record): string => "{$record->name} ({$record->email})")
                ->searchable()
                ->preload()
                ->helperText('Vínculo com o usuário de acesso ao painel administrativo.')
                ->nullable(),
            TextInput::make('queue_position')->label('Posição na Fila')->numeric()->default(1)->required(),
            Toggle::make('is_active')->label('Ativo na Fila')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordUrl(fn (ProposalRepresentative $record): ?string => static::canEdit($record) ? ProposalRepresentativeResource::getUrl('edit', ['record' => $record]) : null)
            ->searchPlaceholder('Buscar por nome ou e-mail do representante...')
            ->defaultSort('queue_position')
            ->defaultPaginationPageOption(10)
            ->paginationPageOptions([10, 25, 50, 100])
            ->emptyStateHeading('Nenhum representante comercial cadastrado')
            ->emptyStateDescription('Cadastre o primeiro representante para começar a distribuir e gerenciar a carteira de propostas.')
            ->emptyStateIcon('heroicon-o-user-group')
            ->columns([
                TextColumn::make('queue_position')
                    ->label('Pos.')
                    ->tooltip('Ordem de distribuição na fila comercial (Round-Robin)')
                    ->formatStateUsing(fn (?int $state): string => filled($state) ? (string) $state : '—')
                    ->weight('semibold')
                    ->color('primary')
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('name')
                    ->label('Representante')
                    ->description(fn (ProposalRepresentative $record): ?string => $record->email)
                    ->weight('semibold')
                    ->wrap()
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%"))
                    ->sortable()
                    ->tooltip(fn (ProposalRepresentative $record): ?string => $record->name),

                TextColumn::make('user.name')
                    ->label('Usuário Vinculado')
                    ->placeholder('Não vinculado')
                    ->icon('heroicon-m-user-circle')
                    ->iconColor('gray')
                    ->color('gray')
                    ->tooltip(fn (ProposalRepresentative $record): ?string => $record->user ? "Conta de acesso: {$record->user->email}" : 'Sem usuário de acesso ao painel associado')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('is_active')
                    ->label('Situação')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Ativo na Fila' : 'Inativo')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray')
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('proposals_count')
                    ->counts('proposals')
                    ->label('Carteira')
                    ->tooltip('Total de propostas comerciais sob responsabilidade deste representante')
                    ->formatStateUsing(fn (int $state): string => match ($state) {
                        0 => '0 propostas',
                        1 => '1 proposta',
                        default => "{$state} propostas",
                    })
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'info' : 'gray')
                    ->url(fn (ProposalRepresentative $record): string => ProposalResource::getUrl('index', [
                        'tableFilters' => [
                            'assigned_representative_id' => [
                                'value' => $record->id,
                            ],
                        ],
                    ]))
                    ->openUrlInNewTab(false)
                    ->alignCenter()
                    ->sortable(),
            ])
            ->filtersFormWidth(Width::Small)
            ->filtersFormMaxHeight('420px')
            ->filters([
                SelectFilter::make('is_active')
                    ->label('Situação na Fila')
                    ->options([
                        '1' => 'Ativo na Fila',
                        '0' => 'Inativo',
                    ]),

                TernaryFilter::make('has_user')
                    ->label('Vínculo com Usuário')
                    ->placeholder('Todos os representantes')
                    ->trueLabel('Com usuário vinculado')
                    ->falseLabel('Sem usuário vinculado')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('user_id'),
                        false: fn (Builder $query): Builder => $query->whereNull('user_id'),
                    ),
            ])
            ->actions([
                ActionGroup::make([
                    EditAction::make()
                        ->label('Editar Representante')
                        ->icon('heroicon-o-pencil-square')
                        ->visible(fn (ProposalRepresentative $record): bool => static::canEdit($record)),

                    DeleteAction::make()
                        ->label('Excluir Representante')
                        ->icon('heroicon-o-trash')
                        ->visible(fn (ProposalRepresentative $record): bool => static::canDelete($record)),
                ])
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->tooltip('Ações do representante'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProposalRepresentatives::route('/'),
            'create' => CreateProposalRepresentative::route('/create'),
            'edit' => EditProposalRepresentative::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->hasAnyRole(['super-admin', 'admin']);
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canDelete(Model $record): bool
    {
        return static::canViewAny();
    }
}
