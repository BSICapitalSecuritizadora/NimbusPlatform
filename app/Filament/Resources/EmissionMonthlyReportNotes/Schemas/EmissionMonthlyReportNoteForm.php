<?php

namespace App\Filament\Resources\EmissionMonthlyReportNotes\Schemas;

use App\Models\EmissionMonthlyReportNote;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class EmissionMonthlyReportNoteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identificação')
                ->columnSpanFull()
                ->schema([
                    Select::make('emission_id')
                        ->label('Emissão')
                        ->relationship('emission', 'name')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->validationMessages([
                            'required' => 'Selecione a emissão.',
                        ]),

                    TextInput::make('reference_month')
                        ->label('Competência')
                        ->placeholder('MM/AAAA')
                        ->mask('99/9999')
                        ->required()
                        ->formatStateUsing(fn (mixed $state): string => EmissionMonthlyReportNote::formatReferenceMonthForDisplay($state))
                        ->dehydrateStateUsing(fn (mixed $state): ?string => EmissionMonthlyReportNote::normalizeReferenceMonth($state))
                        ->mutateStateForValidationUsing(fn (mixed $state): ?string => EmissionMonthlyReportNote::normalizeReferenceMonth($state))
                        ->helperText('Mês de referência do relatório em que a nota deve aparecer.')
                        ->validationMessages([
                            'required' => 'Informe a competência no formato MM/AAAA.',
                        ]),

                    Select::make('category')
                        ->label('Categoria')
                        ->options(EmissionMonthlyReportNote::CATEGORY_OPTIONS)
                        ->default('Geral')
                        ->native(false),
                ])
                ->columns(3),

            Section::make('Conteúdo da Nota')
                ->columnSpanFull()
                ->schema([
                    TextInput::make('title')
                        ->label('Título')
                        ->maxLength(255)
                        ->columnSpanFull(),

                    Textarea::make('content')
                        ->label('Comentário')
                        ->required()
                        ->rows(5)
                        ->columnSpanFull()
                        ->validationMessages([
                            'required' => 'Escreva o comentário/nota.',
                        ]),

                    Toggle::make('is_visible_on_report')
                        ->label('Exibir no relatório mensal')
                        ->default(true)
                        ->helperText('Desative para manter a nota apenas como registro interno, sem exibi-la no PDF.'),
                ])
                ->columns(1),
        ]);
    }
}
