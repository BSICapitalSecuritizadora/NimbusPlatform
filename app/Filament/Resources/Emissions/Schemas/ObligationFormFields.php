<?php

namespace App\Filament\Resources\Emissions\Schemas;

use App\Models\ExtractedObligation;
use App\Models\Obligation;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;

class ObligationFormFields
{
    public const CATEGORY_OPTIONS = [
        'Informacional' => 'Informacional',
        'Covenants' => 'Covenants',
        'Fundos' => 'Fundos',
        'Garantias' => 'Garantias',
        'Recebíveis / Lastro' => 'Recebíveis / Lastro',
        'Obras' => 'Obras',
        'Condições Precedentes' => 'Condições Precedentes',
        'Assembleia / Waiver' => 'Assembleia / Waiver',
        'Vencimento Antecipado' => 'Vencimento Antecipado',
        'Patrimônio Separado' => 'Patrimônio Separado',
        'Regulatória' => 'Regulatória',
        'Financeira / Pagamento' => 'Financeira / Pagamento',
        'Outro' => 'Outro',
    ];

    public const AREA_OPTIONS = [
        'Jurídico' => 'Jurídico',
        'Gestão' => 'Gestão',
        'Emissões' => 'Emissões',
        'Financeiro' => 'Financeiro',
        'Escrituração' => 'Escrituração',
        'Compliance' => 'Compliance',
        'Risco' => 'Risco',
        'Engenharia' => 'Engenharia',
        'Outro' => 'Outro',
    ];

    public const RECURRENCE_OPTIONS = [
        'Única' => 'Única',
        'Mensal' => 'Mensal',
        'Trimestral' => 'Trimestral',
        'Semestral' => 'Semestral',
        'Anual' => 'Anual',
        'Sob demanda' => 'Sob demanda',
        'Outro' => 'Outro',
    ];

    /**
     * @return array<int, \Filament\Forms\Components\Component>
     */
    public static function make(string $mode = 'obligation'): array
    {
        $fields = [
            TextInput::make('title')
                ->label('Título')
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),

            Select::make('obligation_category')
                ->label('Categoria')
                ->options(self::CATEGORY_OPTIONS)
                ->searchable(),

            TextInput::make('obligation_type')
                ->label('Tipo')
                ->maxLength(255),

            Textarea::make('description')
                ->label('Descrição')
                ->rows(3)
                ->columnSpanFull(),

            Select::make('responsible_user_id')
                ->label('Responsável')
                ->relationship('responsibleUser', 'name')
                ->searchable()
                ->preload(),

            Select::make('responsible_area')
                ->label('Área responsável')
                ->options(self::AREA_OPTIONS)
                ->searchable(),

            TextInput::make('responsible_party')
                ->label('Parte responsável (no Termo)')
                ->maxLength(255),

            Select::make('recurrence')
                ->label('Recorrência')
                ->options(self::RECURRENCE_OPTIONS),

            TextInput::make('due_rule')
                ->label('Regra de vencimento')
                ->maxLength(255),

            DatePicker::make('due_date')
                ->label('Vencimento'),

            Select::make('priority')
                ->label('Prioridade')
                ->options(Obligation::PRIORITY_OPTIONS)
                ->default('medium')
                ->required(),
        ];

        if ($mode === 'obligation') {
            $fields[] = Placeholder::make('status_summary')
                ->label('Status atual')
                ->content(fn (?Obligation $record): string => $record?->status_label ?? (Obligation::STATUS_OPTIONS['em_dia'] ?? 'Em dia'));
        }

        $fields[] = Textarea::make('required_evidence')
            ->label('Evidência exigida')
            ->rows(2)
            ->columnSpanFull();

        $fields[] = TextInput::make('source_clause')
            ->label('Cláusula de origem')
            ->maxLength(255);

        $fields[] = TextInput::make('source_page')
            ->label('Página')
            ->numeric();

        $fields[] = Textarea::make('source_excerpt')
            ->label('Trecho de origem')
            ->rows(2)
            ->columnSpanFull();

        if ($mode === 'suggestion') {
            $fields[] = Textarea::make('review_notes')
                ->label('Notas de revisão')
                ->rows(2)
                ->columnSpanFull();
        }

        if ($mode === 'obligation') {
            $fields[] = Textarea::make('notes')
                ->label('Observações')
                ->rows(2)
                ->columnSpanFull();
        }

        return $fields;
    }

    /**
     * Map an approved suggestion into attributes for a consolidated obligation.
     *
     * @return array<string, mixed>
     */
    public static function mapSuggestionToObligation(ExtractedObligation $suggestion): array
    {
        return [
            'extracted_obligation_id' => $suggestion->id,
            'responsible_user_id' => $suggestion->responsible_user_id,
            'title' => $suggestion->title,
            'obligation_type' => $suggestion->obligation_type,
            'obligation_category' => $suggestion->obligation_category,
            'description' => $suggestion->description,
            'responsible_party' => $suggestion->responsible_party,
            'responsible_area' => $suggestion->responsible_area,
            'recurrence' => $suggestion->recurrence,
            'due_rule' => $suggestion->due_rule,
            'due_date' => $suggestion->due_date,
            'priority' => $suggestion->priority,
            'status' => 'em_dia',
            'required_evidence' => $suggestion->required_evidence,
            'source_clause' => $suggestion->source_clause,
            'source_page' => $suggestion->source_page,
            'source_excerpt' => $suggestion->source_excerpt,
        ];
    }
}
