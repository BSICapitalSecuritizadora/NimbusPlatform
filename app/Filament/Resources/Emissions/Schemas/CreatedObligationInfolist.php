<?php

namespace App\Filament\Resources\Emissions\Schemas;

use App\Enums\ObligationDueDateCalculationStatus;
use App\Enums\ObligationFrequency;
use App\Enums\ObligationSeriesStatus;
use App\Models\Document;
use App\Models\Emission;
use App\Models\ExtractedObligation;
use App\Models\Obligation;
use App\Models\ObligationSeries;
use App\Services\Obligations\ObligationScheduleCalculator;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;

/**
 * Ficha somente-leitura da obrigação criada a partir de uma sugestão aprovada.
 *
 * Todo valor vem do registro atual — a obrigação ou, sem ela, a série —, que
 * pode ter sido editado depois da aprovação. A sugestão só alimenta a seção de
 * origem. Cada entrada recebe estado explícito: sem ele, o Filament leria o
 * atributo homônimo da sugestão, que é o registro da linha da tabela.
 */
class CreatedObligationInfolist
{
    /**
     * @return array<int, Component>
     */
    public static function make(
        Emission $emission,
        ExtractedObligation $suggestion,
        ?Obligation $obligation,
        ?ObligationSeries $series,
        ?Document $document,
        ?string $documentUrl,
    ): array {
        $target = $obligation ?? $series;

        if ($target === null) {
            return self::missing();
        }

        return [
            self::generalSection($emission, $target, $obligation, $series),
            self::foundationSection($target, $document, $documentUrl),
            self::scheduleSection($target, $obligation, $series),
            self::originSection($suggestion, $target),
        ];
    }

    /**
     * @return array<int, Component>
     */
    public static function missing(): array
    {
        return [
            Callout::make('Obrigação não encontrada')
                ->description('A obrigação vinculada a esta sugestão não está mais disponível.')
                ->warning(),
        ];
    }

    protected static function generalSection(Emission $emission, Obligation|ObligationSeries $target, ?Obligation $obligation, ?ObligationSeries $series): Section
    {
        return Section::make('Informações gerais')
            ->compact()
            ->schema([
                Grid::make(['default' => 1, 'sm' => 2])->schema([
                    TextEntry::make('title')
                        ->label('Título')
                        ->state($target->title)
                        ->weight('bold')
                        ->columnSpanFull(),
                    $obligation !== null
                        ? TextEntry::make('status')
                            ->label('Situação')
                            ->state(Obligation::STATUS_OPTIONS[$obligation->status] ?? $obligation->status)
                            ->badge()
                            ->color(match ($obligation->status) {
                                'em_dia', 'concluida' => 'success',
                                'a_vencer' => 'info',
                                'vencida' => 'danger',
                                'em_analise' => 'warning',
                                default => 'gray',
                            })
                        : TextEntry::make('status')
                            ->label('Situação')
                            ->state($series?->status_label)
                            ->badge()
                            ->color(self::seriesStatusColor($series?->status)),
                    TextEntry::make('responsible')
                        ->label('Responsável')
                        ->state($target->responsibleUser?->name)
                        ->placeholder('—'),
                    TextEntry::make('emission')
                        ->label('Emissão')
                        ->state($emission->name),
                    self::optionalEntry('obligation_category', 'Categoria', $target->obligation_category),
                    self::optionalEntry('obligation_type', 'Tipo', $target->obligation_type),
                    self::optionalEntry('priority', 'Prioridade', Obligation::PRIORITY_OPTIONS[$target->priority] ?? $target->priority)
                        ->badge()
                        ->color(match ($target->priority) {
                            'critical' => 'danger',
                            'high' => 'warning',
                            'medium' => 'info',
                            default => 'gray',
                        }),
                    self::optionalEntry('competence', 'Competência', $obligation?->competence_label),
                    self::optionalEntry('responsible_area', 'Área responsável', $target->responsible_area),
                    self::optionalEntry('responsible_party', 'Parte responsável (no Termo)', $target->responsible_party),
                ]),
            ]);
    }

    protected static function foundationSection(Obligation|ObligationSeries $target, ?Document $document, ?string $documentUrl): Section
    {
        $excerpt = $target->source_excerpt !== $target->description ? $target->source_excerpt : null;

        return Section::make('Fundamentação')
            ->compact()
            ->schema([
                TextEntry::make('description')
                    ->label('Descrição')
                    ->hiddenLabel()
                    ->state($target->description)
                    ->visible(filled($target->description)),
                self::optionalEntry('source_excerpt', 'Trecho de origem', $excerpt),
                Grid::make(['default' => 1, 'sm' => 3])->schema([
                    self::optionalEntry('source_clause', 'Cláusula', $target->source_clause),
                    self::optionalEntry('source_page', 'Página', $target->source_page),
                    self::optionalEntry('source_document', 'Documento', $document?->title),
                ]),
                Actions::make([
                    Action::make('open_created_obligation_source')
                        ->label('Abrir no documento')
                        ->icon('heroicon-o-document-magnifying-glass')
                        ->link()
                        ->url($documentUrl, shouldOpenInNewTab: true),
                ])
                    ->visible($documentUrl !== null),
            ])
            ->visible(filled($target->description)
                || filled($excerpt)
                || filled($target->source_clause)
                || filled($target->source_page)
                || $document !== null);
    }

    protected static function scheduleSection(Obligation|ObligationSeries $target, ?Obligation $obligation, ?ObligationSeries $series): Section
    {
        $isAwaitingCalendar = $obligation?->due_date_calculation_status === ObligationDueDateCalculationStatus::AwaitingCalendar;
        $latestRule = $series?->rules->last();

        return Section::make('Prazo e recorrência')
            ->compact()
            ->schema([
                self::optionalEntry('due_rule', 'Prazo', $target->due_rule),
                Grid::make(['default' => 1, 'sm' => 2])->schema([
                    self::optionalEntry('due_date', 'Vencimento', $isAwaitingCalendar
                        ? 'Aguardando cobertura do calendário'
                        : $obligation?->due_date?->format('d/m/Y'))
                        ->color($isAwaitingCalendar ? 'warning' : null),
                    TextEntry::make('recurrence')
                        ->label('Recorrência')
                        ->state($series !== null ? $series->frequency_label : $obligation?->recurrence)
                        ->placeholder('Única'),
                    self::optionalEntry('series_status', 'Situação da recorrência', $obligation !== null ? $series?->status_label : null)
                        ->badge()
                        ->color(self::seriesStatusColor($series?->status)),
                    self::optionalEntry('rule_version', 'Versão da regra', $latestRule !== null
                        ? "Versão {$latestRule->version} · vigente a partir de {$latestRule->effective_from->format('d/m/Y')}"
                        : null),
                    self::optionalEntry('next_occurrence', 'Próxima ocorrência', self::hasScheduledOccurrences($series)
                        ? app(ObligationScheduleCalculator::class)->nextOccurrenceLabel($series)
                        : null),
                ]),
                self::optionalEntry('rule_summary', 'Regra executável', $series?->rule_summary),
                self::optionalEntry('required_evidence', 'Evidência exigida', $target->required_evidence),
            ]);
    }

    protected static function originSection(ExtractedObligation $suggestion, Obligation|ObligationSeries $target): Section
    {
        $wasUpdatedAfterCreation = $target->created_at !== null
            && $target->updated_at?->gt($target->created_at) === true;

        return Section::make('Origem')
            ->compact()
            ->schema([
                TextEntry::make('origin')
                    ->label('Origem')
                    ->hiddenLabel()
                    ->state('Criada a partir de sugestão da IA')
                    ->weight('medium'),
                Grid::make(['default' => 1, 'sm' => 2])->schema([
                    TextEntry::make('suggestion_status')
                        ->label('Status da sugestão')
                        ->state(ExtractedObligation::STATUS_OPTIONS[$suggestion->status] ?? $suggestion->status)
                        ->badge()
                        ->color(match ($suggestion->status) {
                            ExtractedObligation::STATUS_APPROVED => 'success',
                            ExtractedObligation::STATUS_REJECTED => 'danger',
                            default => 'warning',
                        }),
                    self::optionalEntry('suggestion_reviewer', 'Revisada por', $suggestion->reviewer?->name),
                    self::optionalEntry('suggestion_reviewed_at', 'Revisada em', $suggestion->reviewed_at)
                        ->dateTime('d/m/Y H:i'),
                    self::optionalEntry('created_at', 'Criada em', $target->created_at)
                        ->dateTime('d/m/Y H:i'),
                    self::optionalEntry('updated_at', 'Última atualização', $wasUpdatedAfterCreation ? $target->updated_at : null)
                        ->dateTime('d/m/Y H:i'),
                ]),
                self::optionalEntry('review_notes', 'Observação da revisão', $suggestion->review_notes),
                TextEntry::make('current_state_notice')
                    ->label('Aviso')
                    ->hiddenLabel()
                    ->state('Os dados acima refletem o estado atual da obrigação, que pode ter sido ajustada depois da aprovação da sugestão.')
                    ->color('gray')
                    ->size('xs'),
            ]);
    }

    /**
     * Entrada que só aparece quando há valor — a ficha não inventa conteúdo
     * para campo vazio.
     */
    protected static function optionalEntry(string $name, string $label, mixed $state): TextEntry
    {
        return TextEntry::make($name)
            ->label($label)
            ->state($state)
            ->visible(filled($state));
    }

    /**
     * Fora de uma série ativa com agenda, o rótulo da próxima ocorrência só
     * repetiria a situação ou a recorrência já exibidas.
     */
    protected static function hasScheduledOccurrences(?ObligationSeries $series): bool
    {
        return $series?->status === ObligationSeriesStatus::Active
            && $series->frequency !== ObligationFrequency::OnDemand;
    }

    protected static function seriesStatusColor(?ObligationSeriesStatus $status): string
    {
        return match ($status) {
            ObligationSeriesStatus::Active => 'success',
            ObligationSeriesStatus::AwaitingConfiguration => 'warning',
            default => 'gray',
        };
    }
}
