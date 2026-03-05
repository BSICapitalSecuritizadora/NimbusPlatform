<?php

namespace App\Filament\Resources\Investors;

use App\Filament\Resources\Investors\Pages\CreateInvestor;
use App\Filament\Resources\Investors\Pages\EditInvestor;
use App\Filament\Resources\Investors\Pages\ListInvestors;
use App\Models\Investor;
use BackedEnum;
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
use Illuminate\Support\Facades\Hash;

class InvestorResource extends Resource
{
    protected static ?string $model = Investor::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Investidores';

    protected static ?string $modelLabel = 'Investidor';

    protected static ?string $pluralModelLabel = 'Investidores';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Dados do investidor')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nome')
                            ->required()
                            ->maxLength(255),

                        TextInput::make('email')
                            ->label('E-mail')
                            ->email()
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),

                        TextInput::make('password')
                            ->label('Senha')
                            ->password()
                            ->revealable()
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(fn ($state): bool => filled($state))
                            ->dehydrateStateUsing(fn ($state) => filled($state) ? Hash::make($state) : null)
                            ->maxLength(255),

                        TextInput::make('phone')
                            ->label('Telefone')
                            ->tel()
                            ->placeholder('(xx) xxxx-xxxx')
                            ->rule('regex:/^\(\d{2}\)\s\d{4}-\d{4}$/')
                            ->validationMessages([
                                'regex' => 'Informe o telefone no formato (xx) xxxx-xxxx.',
                            ])
                            ->maxLength(20),

                        TextInput::make('mobile')
                            ->label('Celular')
                            ->tel()
                            ->placeholder('(xx) xxxxx-xxxx')
                            ->rule('regex:/^\(\d{2}\)\s\d{5}-\d{4}$/')
                            ->validationMessages([
                                'regex' => 'Informe o celular no formato (xx) xxxxx-xxxx.',
                            ])
                            ->maxLength(20),

                        TextInput::make('cpf')
                            ->label('CPF')
                            ->placeholder('xxx.xxx.xxx-xx')
                            ->rule('regex:/^\d{3}\.\d{3}\.\d{3}-\d{2}$/')
                            ->validationMessages([
                                'regex' => 'Informe o CPF no formato xxx.xxx.xxx-xx.',
                            ])
                            ->maxLength(14),

                        TextInput::make('rg')
                            ->label('RG')
                            ->placeholder('xx.xxx.xxx-x')
                            ->rule('regex:/^\d{2}\.\d{3}\.\d{3}-[\dXx]$/')
                            ->validationMessages([
                                'regex' => 'Informe o RG no formato xx.xxx.xxx-x.',
                            ])
                            ->maxLength(12),

                        Select::make('emissions')
                            ->label('Emissões')
                            ->relationship('emissions', 'name')
                            ->multiple()
                            ->preload()
                            ->searchable()
                            ->placeholder('Nenhuma emissão vinculada no momento')
                            ->columnSpanFull(),

                        Toggle::make('is_active')
                            ->label('Ativo')
                            ->default(true),

                        DateTimePicker::make('last_login_at')
                            ->label('Último login'),

                        Textarea::make('notes')
                            ->label('Observações')
                            ->rows(4)
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
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('email')
                    ->label('E-mail')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('phone')
                    ->label('Telefone')
                    ->toggleable(),

                TextColumn::make('mobile')
                    ->label('Celular')
                    ->toggleable(),

                TextColumn::make('cpf')
                    ->label('CPF')
                    ->toggleable(),

                TextColumn::make('rg')
                    ->label('RG')
                    ->toggleable(),

                IconColumn::make('is_active')
                    ->label('Ativo')
                    ->boolean(),

                TextColumn::make('last_login_at')
                    ->label('Último login')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name');
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