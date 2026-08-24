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
                    ->schema([
                        TextInput::make('title')
                            ->label('Título da Vaga')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        TextInput::make('slug')
                            ->label('URL Amigável (Slug)')
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText('Gerada automaticamente a partir do título. A URL permanece estável após a publicação; editar o título não altera o link existente.'),
                        Select::make('department')
                            ->label('Departamento / Área')
                            ->options(VacancyDepartment::options())
                            ->searchable()
                            ->required()
                            ->native(false)
                            ->placeholder('Selecione'),
                        Select::make('type')
                            ->label('Tipo de Contratação')
                            ->options(VacancyEmploymentType::options())
                            ->required()
                            ->native(false)
                            ->default(VacancyEmploymentType::CLT->value),
                        Select::make('work_model')
                            ->label('Modelo de Trabalho')
                            ->options(VacancyWorkModel::options())
                            ->placeholder('Selecione')
                            ->native(false),
                        TextInput::make('location')
                            ->label('Localização')
                            ->placeholder('Ex: São Paulo, SP')
                            ->maxLength(255),
                        TextInput::make('positions')
                            ->label('Vagas Disponíveis')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(999)
                            ->default(1)
                            ->required(),
                        Select::make('hiring_manager_id')
                            ->label('Gestor Responsável')
                            ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->preload()
                            ->placeholder('Selecione'),
                    ])
                    ->columns(2),

                Section::make('Publicação')
                    ->schema([
                        Select::make('status')
                            ->label('Status')
                            ->options(VacancyStatus::options())
                            ->required()
                            ->native(false)
                            ->default(VacancyStatus::Draft->value),
                        DateTimePicker::make('published_at')
                            ->label('Data de Publicação')
                            ->helperText('Preenchida automaticamente ao publicar. Ajuste manualmente se necessário.')
                            ->native(false)
                            ->seconds(false),
                        DateTimePicker::make('expires_at')
                            ->label('Data de Expiração')
                            ->helperText('Quando preenchida, a vaga será encerrada automaticamente após esta data (01:00).')
                            ->native(false)
                            ->seconds(false)
                            ->after('published_at'),
                    ])
                    ->columns(3),

                Section::make('Remuneração')
                    ->schema([
                        TextInput::make('salary_min')
                            ->label('Salário Mínimo (R$)')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(9999999)
                            ->placeholder('Ex: 5000'),
                        TextInput::make('salary_max')
                            ->label('Salário Máximo (R$)')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(9999999)
                            ->placeholder('Ex: 8000'),
                        Toggle::make('salary_visible')
                            ->label('Exibir faixa salarial no site')
                            ->helperText('Quando desativado, a faixa permanece registrada apenas para uso interno.')
                            ->default(false),
                    ])
                    ->columns(3)
                    ->collapsible(),

                Section::make('Conteúdo da Vaga')
                    ->schema([
                        RichEditor::make('description')
                            ->label('Descrição')
                            ->required()
                            ->columnSpanFull(),
                        RichEditor::make('requirements')
                            ->label('Requisitos e Qualificações')
                            ->columnSpanFull(),
                        RichEditor::make('benefits')
                            ->label('Benefícios')
                            ->columnSpanFull(),
                    ])
                    ->collapsible(),

                Section::make('Informações Internas')
                    ->schema([
                        Textarea::make('internal_notes')
                            ->label('Observações Internas')
                            ->placeholder('Notas visíveis apenas à equipe de recrutamento. Não aparecem no site.')
                            ->rows(4)
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed(),
            ]);
    }
}
