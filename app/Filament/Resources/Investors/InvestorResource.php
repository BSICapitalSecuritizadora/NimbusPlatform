<?php

namespace App\Filament\Resources\Investors;

use App\Filament\Resources\Investors\Pages\CreateInvestor;
use App\Filament\Resources\Investors\Pages\EditInvestor;
use App\Filament\Resources\Investors\Pages\ListInvestors;
use App\Models\Investor;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use UnitEnum;

class InvestorResource extends Resource
{
    protected static ?string $model = Investor::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $navigationLabel = 'Investidores';

    protected static ?string $modelLabel = 'Investidor';

    protected static ?string $pluralModelLabel = 'Investidores';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|UnitEnum|null $navigationGroup = 'Comercial';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Dados Principais')
                    ->description('Informações básicas de identificação e acesso.')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nome Completo / Razão Social')
                            ->required()
                            ->maxLength(255),

                        TextInput::make('email')
                            ->label('E-mail Institucional')
                            ->email()
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),

                        TextInput::make('password')
                            ->label('Senha de Acesso')
                            ->password()
                            ->revealable()
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(fn ($state): bool => filled($state))
                            ->dehydrateStateUsing(fn ($state) => filled($state) ? Hash::make($state) : null)
                            ->maxLength(255),

                        Toggle::make('is_active')
                            ->label('Status de Ativação')
                            ->default(true)
                            ->inline(false),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make('Documentos')
                    ->description('Documentação pessoal.')
                    ->schema([
                        TextInput::make('cpf')
                            ->label('CPF')
                            ->placeholder('123.456.789-00')
                            ->mask('999.999.999-99')
                            ->rule('regex:/^\d{3}\.\d{3}\.\d{3}-\d{2}$/')
                            ->validationMessages([
                                'regex' => 'Use o formato xxx.xxx.xxx-xx.',
                            ])
                            ->maxLength(14),

                        TextInput::make('rg')
                            ->label('RG')
                            ->placeholder('12.345.678-9')
                            ->mask('99.999.999-*')
                            ->rule('regex:/^\d{2}\.\d{3}\.\d{3}-[\dXx]$/')
                            ->validationMessages([
                                'regex' => 'Use o formato xx.xxx.xxx-x.',
                            ])
                            ->maxLength(12),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make('Contato')
                    ->description('Informações para comunicação.')
                    ->schema([
                        TextInput::make('phone')
                            ->label('Telefone Fixo')
                            ->tel()
                            ->placeholder('(11) 3333-4444')
                            ->mask('(99) 9999-9999')
                            ->rule('regex:/^\(\d{2}\)\s\d{4}-\d{4}$/')
                            ->validationMessages([
                                'regex' => 'Use o formato (xx) xxxx-xxxx.',
                            ])
                            ->maxLength(20),

                        TextInput::make('mobile')
                            ->label('Telefone Celular')
                            ->tel()
                            ->placeholder('(11) 98888-7777')
                            ->mask('(99) 99999-9999')
                            ->rule('regex:/^\(\d{2}\)\s\d{5}-\d{4}$/')
                            ->validationMessages([
                                'regex' => 'Use o formato (xx) xxxxx-xxxx.',
                            ])
                            ->maxLength(20),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make('Operações e Acessos')
                    ->description('Vínculos com operações e histórico de acessos ao sistema.')
                    ->schema([
                        Select::make('emissions')
                            ->label('Operações Vinculadas')
                            ->relationship('emissions', 'name')
                            ->multiple()
                            ->preload()
                            ->searchable()
                            ->placeholder('Nenhuma operação vinculada.')
                            ->columnSpanFull(),

                        DateTimePicker::make('last_login_at')
                            ->label('Último Acesso ao Sistema'),

                        DateTimePicker::make('last_portal_seen_at')
                            ->label('Última Interação no Portal'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make('Observações')
                    ->description('Anotações e informações complementares.')
                    ->schema([
                        Textarea::make('notes')
                            ->label('Informações Complementares / Notas')
                            ->rows(6)
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordUrl(fn (Investor $record): ?string => static::canEdit($record) ? InvestorResource::getUrl('edit', ['record' => $record]) : null)
            ->searchPlaceholder('Buscar por investidor, CPF ou e-mail...')
            ->defaultSort('name')
            ->defaultPaginationPageOption(10)
            ->paginationPageOptions([10, 25, 50, 100])
            ->emptyStateHeading('Nenhum investidor cadastrado')
            ->emptyStateDescription('Cadastre o primeiro investidor para começar a gerenciar seus acessos e relacionamento.')
            ->emptyStateIcon('heroicon-o-users')
            ->columns([
                TextColumn::make('name')
                    ->label('Investidor')
                    ->description(fn (Investor $record): ?string => $record->cpf ? "CPF {$record->cpf}" : ($record->rg ? "RG {$record->rg}" : null))
                    ->weight('semibold')
                    ->wrap()
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('cpf', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%"))
                    ->sortable()
                    ->tooltip(fn (Investor $record): ?string => $record->name),

                TextColumn::make('email')
                    ->label('Contato')
                    ->description(fn (Investor $record): ?string => collect([$record->mobile, $record->phone])->filter()->implode(' · '))
                    ->placeholder('Sem contato cadastrado')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('is_active')
                    ->label('Situação')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Ativo' : 'Inativo')
                    ->color(fn (bool $state): string => $state ? 'success' : 'danger')
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('last_login_at')
                    ->label('Último Acesso')
                    ->dateTime('d/m/Y H:i')
                    ->description(fn (Investor $record): ?string => $record->last_login_at?->diffForHumans())
                    ->placeholder('Nunca acessou')
                    ->sortable(),

                TextColumn::make('phone')
                    ->label('Telefone Fixo')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('mobile')
                    ->label('Telefone Celular')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('cpf')
                    ->label('CPF')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('rg')
                    ->label('RG')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('last_portal_seen_at')
                    ->label('Última Interação')
                    ->dateTime('d/m/Y H:i')
                    ->description(fn (Investor $record): ?string => $record->last_portal_seen_at?->diffForHumans())
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Data de Registro')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filtersFormWidth(Width::Small)
            ->filtersFormMaxHeight('420px')
            ->filters([
                SelectFilter::make('is_active')
                    ->label('Situação do Investidor')
                    ->options([
                        '1' => 'Ativo',
                        '0' => 'Inativo',
                    ]),
            ])
            ->actions([
                ActionGroup::make([
                    EditAction::make()
                        ->label('Editar Cadastro')
                        ->icon('heroicon-o-pencil-square')
                        ->visible(fn (Investor $record): bool => static::canEdit($record)),

                    DeleteAction::make()
                        ->label('Excluir Investidor')
                        ->icon('heroicon-o-trash')
                        ->visible(fn (Investor $record): bool => static::canDelete($record)),
                ])
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->tooltip('Ações do investidor'),
            ]);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()->can('investors.view');
    }

    public static function canCreate(): bool
    {
        return auth()->user()->can('investors.create');
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()->can('investors.update');
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()->can('investors.delete');
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvestors::route('/'),
            'create' => CreateInvestor::route('/create'),
            'edit' => EditInvestor::route('/{record}/edit'),
        ];
    }
}
