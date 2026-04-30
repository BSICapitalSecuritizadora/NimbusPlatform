<?php

namespace App\Filament\Resources\Investors;

use App\Filament\Resources\Investors\Pages\CreateInvestor;
use App\Filament\Resources\Investors\Pages\EditInvestor;
use App\Filament\Resources\Investors\Pages\ListInvestors;
use App\Models\Investor;
use BackedEnum;
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
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
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

    protected static string|UnitEnum|null $navigationGroup = 'Cadastro';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identificação do Investidor')
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

                        Select::make('emissions')
                            ->label('Operações Vinculadas')
                            ->relationship('emissions', 'name')
                            ->multiple()
                            ->preload()
                            ->searchable()
                            ->placeholder('Nenhuma operação vinculada.')
                            ->columnSpanFull(),

                        Toggle::make('is_active')
                            ->label('Status de Ativação')
                            ->default(true),

                        DateTimePicker::make('last_login_at')
                            ->label('Último Acesso ao Sistema'),

                        DateTimePicker::make('last_portal_seen_at')
                            ->label('Última Interação no Portal'),

                        Textarea::make('notes')
                            ->label('Informações Complementares / Notas')
                            ->rows(6)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Denominação do Investidor')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('email')
                    ->label('E-mail')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('phone')
                    ->label('Telefone Fixo')
                    ->toggleable(),

                TextColumn::make('mobile')
                    ->label('Telefone Celular')
                    ->toggleable(),

                TextColumn::make('cpf')
                    ->label('CPF')
                    ->toggleable(),

                TextColumn::make('rg')
                    ->label('RG')
                    ->toggleable(),

                IconColumn::make('is_active')
                    ->label('Situação')
                    ->boolean(),

                TextColumn::make('last_login_at')
                    ->label('Último Acesso')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('last_portal_seen_at')
                    ->label('Última Interação')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Data de Registro')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                EditAction::make()
                    ->visible(fn (): bool => auth()->user()->can('investors.update')),

                DeleteAction::make()
                    ->visible(fn (): bool => auth()->user()->can('investors.delete')),
            ])
            ->defaultSort('name');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()->can('investors.view');
    }

    public static function canCreate(): bool
    {
        return auth()->user()->can('investors.create');
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
