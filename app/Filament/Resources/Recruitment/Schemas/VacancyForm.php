<?php

namespace App\Filament\Resources\Recruitment\Schemas;

use App\Enums\VacancyDepartment;
use App\Enums\VacancyEmploymentType;
use App\Enums\VacancyStatus;
use App\Enums\VacancyWorkModel;
use App\Models\User;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class VacancyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identificação da Vaga')
                    ->description('Informações principais da oportunidade e sua classificação.')
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('title')
                            ->label('Título da Vaga')
                            ->placeholder('Ex: Analista de Estruturação Sr')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),

                        TextInput::make('slug')
                            ->label('URL Amigável (Slug)')
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText('Gerada automaticamente a partir do título. A URL permanece estável após a publicação.')
                            ->columnSpan(['default' => 12, 'sm' => 12, 'md' => 6, 'lg' => 6]),

                        Select::make('department')
                            ->label('Departamento / Área')
                            ->options(VacancyDepartment::options())
                            ->searchable()
                            ->required()
                            ->native(false)
                            ->placeholder('Selecione o departamento')
                            ->columnSpan(['default' => 12, 'sm' => 12, 'md' => 6, 'lg' => 6]),

                        Select::make('type')
                            ->label('Tipo de Contratação')
                            ->options(VacancyEmploymentType::options())
                            ->required()
                            ->native(false)
                            ->default(VacancyEmploymentType::CLT->value)
                            ->columnSpan(['default' => 12, 'sm' => 12, 'md' => 6, 'lg' => 6]),

                        Select::make('work_model')
                            ->label('Modelo de Trabalho')
                            ->options(VacancyWorkModel::options())
                            ->placeholder('Selecione o modelo')
                            ->native(false)
                            ->columnSpan(['default' => 12, 'sm' => 12, 'md' => 6, 'lg' => 6]),

                        TextInput::make('location')
                            ->label('Localização')
                            ->placeholder('Ex: São Paulo, SP (ou Remoto)')
                            ->maxLength(255)
                            ->columnSpan(['default' => 12, 'sm' => 12, 'md' => 8, 'lg' => 8]),

                        TextInput::make('positions')
                            ->label('Vagas Disponíveis')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(999)
                            ->default(1)
                            ->required()
                            ->columnSpan(['default' => 12, 'sm' => 12, 'md' => 4, 'lg' => 4]),

                        Select::make('hiring_manager_id')
                            ->label('Gestor Responsável')
                            ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->preload()
                            ->placeholder('Selecione o gestor responsável')
                            ->columnSpanFull(),
                    ])
                    ->columns(['default' => 1, 'sm' => 12, 'md' => 12, 'lg' => 12]),

                Section::make('Publicação')
                    ->description('Defina o status e o período de disponibilidade da vaga.')
                    ->columnSpanFull()
                    ->schema([
                        Select::make('status')
                            ->label('Status')
                            ->options(VacancyStatus::options())
                            ->required()
                            ->native(false)
                            ->default(VacancyStatus::Draft->value)
                            ->columnSpan(['default' => 12, 'sm' => 12, 'md' => 4, 'lg' => 4]),

                        DateTimePicker::make('published_at')
                            ->label('Data de Publicação')
                            ->helperText('Preenchida automaticamente ao publicar.')
                            ->native(false)
                            ->seconds(false)
                            ->columnSpan(['default' => 12, 'sm' => 12, 'md' => 4, 'lg' => 4]),

                        DateTimePicker::make('expires_at')
                            ->label('Data de Expiração')
                            ->helperText('A vaga encerra automaticamente nesta data.')
                            ->native(false)
                            ->seconds(false)
                            ->after('published_at')
                            ->columnSpan(['default' => 12, 'sm' => 12, 'md' => 4, 'lg' => 4]),
                    ])
                    ->columns(['default' => 1, 'sm' => 12, 'md' => 12, 'lg' => 12]),

                Section::make('Remuneração')
                    ->description('Configure a faixa salarial e sua visibilidade no site institucional.')
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('salary_min')
                            ->label('Salário Mínimo')
                            ->prefix('R$')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(9999999)
                            ->placeholder('0,00')
                            ->columnSpan(['default' => 12, 'sm' => 12, 'md' => 4, 'lg' => 4]),

                        TextInput::make('salary_max')
                            ->label('Salário Máximo')
                            ->prefix('R$')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(9999999)
                            ->placeholder('0,00')
                            ->columnSpan(['default' => 12, 'sm' => 12, 'md' => 4, 'lg' => 4]),

                        Toggle::make('salary_visible')
                            ->label('Exibir faixa salarial no site')
                            ->helperText('Quando desativado, permanece visível apenas internamente.')
                            ->default(false)
                            ->columnSpan(['default' => 12, 'sm' => 12, 'md' => 4, 'lg' => 4]),
                    ])
                    ->columns(['default' => 1, 'sm' => 12, 'md' => 12, 'lg' => 12]),

                Section::make('Conteúdo da Vaga')
                    ->description('Informações detalhadas apresentadas aos candidatos no portal institucional.')
                    ->columnSpanFull()
                    ->schema([
                        RichEditor::make('description')
                            ->label('Descrição da Vaga')
                            ->required()
                            ->columnSpanFull(),

                        RichEditor::make('requirements')
                            ->label('Requisitos e Qualificações')
                            ->columnSpanFull(),

                        RichEditor::make('benefits')
                            ->label('Benefícios e Vantagens')
                            ->columnSpanFull(),
                    ]),

                Section::make('Informações Internas')
                    ->description('Observações exclusivas para acompanhamento da equipe administrativa.')
                    ->columnSpanFull()
                    ->schema([
                        Textarea::make('internal_notes')
                            ->label('Observações Internas')
                            ->placeholder('Notas visíveis apenas à equipe de recrutamento. Não aparecem no site institucional.')
                            ->rows(4)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
