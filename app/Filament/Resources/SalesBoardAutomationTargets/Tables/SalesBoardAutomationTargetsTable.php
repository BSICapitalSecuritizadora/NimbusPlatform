<?php

namespace App\Filament\Resources\SalesBoardAutomationTargets\Tables;

use App\Enums\SalesBoardAutomationTargetStatus;
use App\Models\SalesBoardAutomationTarget;
use App\Support\BusinessTime;
use App\Support\SalesBoards\SalesBoardAutomationConfig;
use App\Support\SalesBoards\SalesBoardIssuePresenter;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * A lista que o time abre para saber o que a automação não conseguiu fazer.
 *
 * A ordenação padrão põe o que precisa de ação no topo: bloqueados e falhos
 * antes de satisfeitos, e dentro disso a competência mais antiga primeiro --
 * porque é a que está parada há mais tempo.
 */
class SalesBoardAutomationTargetsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('construction.development_name')
                    ->label('Empreendimento')
                    ->searchable()
                    ->wrap(),

                TextColumn::make('reference_month')
                    ->label('Competência')
                    ->formatStateUsing(fn (SalesBoardAutomationTarget $record): string => $record->referenceMonthLabel())
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Situação')
                    ->badge()
                    ->formatStateUsing(fn (SalesBoardAutomationTargetStatus $state): string => $state->label())
                    ->color(fn (SalesBoardAutomationTargetStatus $state): string => $state->color()),

                TextColumn::make('attempt_count')
                    ->label('Tentativas')
                    ->alignRight()
                    ->sortable(),

                /**
                 * O motivo em linguagem de tela, com o código logo abaixo: o
                 * código continua sendo o que suporte e log procuram, mas não
                 * pode ser a única coisa que o operador lê. A falha técnica
                 * mostra a mensagem já sanitizada na persistência.
                 */
                TextColumn::make('stop_reason')
                    ->label('Motivo da parada')
                    ->state(fn (SalesBoardAutomationTarget $record): ?string => match ($record->status) {
                        SalesBoardAutomationTargetStatus::Blocked => collect(SalesBoardIssuePresenter::describe($record->blockerCodes()))
                            ->pluck('label')
                            ->implode('; ') ?: $record->currentReason(),
                        SalesBoardAutomationTargetStatus::Failed,
                        SalesBoardAutomationTargetStatus::Closed => $record->currentReason(),
                        default => null,
                    })
                    ->description(fn (SalesBoardAutomationTarget $record): ?string => match ($record->status) {
                        SalesBoardAutomationTargetStatus::Blocked => (implode(', ', $record->blockerCodes())) ?: null,
                        SalesBoardAutomationTargetStatus::Closed => self::closureDescription($record),
                        default => null,
                    })
                    ->placeholder('—')
                    ->wrap(),

                // Competência satisfeita ou encerrada não está parada: a data só aparece para o que exige ação.
                TextColumn::make('first_attempt_at')
                    ->label('Parado desde')
                    ->state(fn (SalesBoardAutomationTarget $record): mixed => $record->status->isOpen() ? $record->first_attempt_at : null)
                    ->dateTime('d/m/Y H:i', BusinessTime::timezone())
                    ->placeholder('—')
                    ->visibleFrom('lg'),

                TextColumn::make('last_attempt_at')
                    ->label('Última tentativa')
                    ->dateTime('d/m/Y H:i', BusinessTime::timezone())
                    ->placeholder('—')
                    ->visibleFrom('lg'),

                TextColumn::make('next_attempt_at')
                    ->label('Próxima tentativa')
                    ->dateTime('d/m/Y H:i', BusinessTime::timezone())
                    ->placeholder('—'),

                TextColumn::make('cycle.id')
                    ->label('Ciclo')
                    ->placeholder('—')
                    ->formatStateUsing(fn (mixed $state): string => '#'.$state)
                    ->visibleFrom('md'),
            ])
            ->defaultSort('reference_month')
            ->filters([
                SelectFilter::make('status')
                    ->label('Situação')
                    ->options(collect(SalesBoardAutomationTargetStatus::cases())
                        ->mapWithKeys(fn (SalesBoardAutomationTargetStatus $case): array => [
                            $case->value => $case->label(),
                        ])
                        ->all()),
            ])
            /**
             * Sem ações de linha: não há o que fazer daqui que não seja
             * conduzido pelo scheduler.
             */
            ->recordActions([])
            ->toolbarActions([])
            /**
             * Lista vazia não pode parecer erro. Sem nenhum alvo, a explicação é
             * a da automação ainda sem escopo; com alvos em outras abas, o vazio
             * da aba é uma boa notícia, e a mensagem diz isso.
             */
            ->emptyStateHeading(fn (HasTable $livewire): string => self::emptyState($livewire)[0])
            ->emptyStateDescription(fn (HasTable $livewire): string => self::emptyState($livewire)[1]);
    }

    /**
     * Motivo, quando e por quem, na própria linha.
     *
     * A tela não tem página de detalhe, e o encerramento é trilha: quem abre a
     * aba "Encerrados" precisa ver ali quem devolveu a Emissão ao legado, sem
     * procurar o evento do rollout. Sem autor, quem encerrou foi a própria
     * automação (escopo suspenso na execução horária).
     */
    private static function closureDescription(SalesBoardAutomationTarget $record): ?string
    {
        $parts = array_filter([
            $record->closure_reason?->label(),
            $record->closed_at === null ? null : 'em '.BusinessTime::at($record->closed_at)->format('d/m/Y H:i'),
            'por '.($record->closedBy?->name ?? 'automação'),
        ]);

        return implode(' · ', $parts);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function emptyState(HasTable $livewire): array
    {
        if (! SalesBoardAutomationTarget::query()->exists()) {
            return [
                'Nenhuma competência sob automação',
                'A automação nasce desligada. Enquanto nenhum empreendimento for habilitado, '
                    .'nada é descoberto e nada é gerado.',
            ];
        }

        $activeTab = $livewire->activeTab ?? null;

        /**
         * Desligada, a automação não atende empreendimento nenhum -- e o que ela
         * não atende não fica pendente dela. A mensagem diz por que a aba está
         * vazia, para ninguém ler "nada pendente" como "tudo em dia".
         */
        if ($activeTab === 'pendentes' && ! SalesBoardAutomationConfig::enabled()) {
            return [
                'Automação desligada',
                'Com o interruptor global desligado nada é tentado, e nenhuma competência fica pendente da automação. '
                    .'As competências abertas continuam visíveis nas abas de bloqueios, falhas e "Todos".',
            ];
        }

        return match ($activeTab) {
            'pendentes' => ['Nada pendente de ação', 'Nenhuma competência da automação está bloqueada, com falha ou aguardando processamento.'],
            'bloqueados' => ['Não há alvos bloqueados', 'Nenhuma competência está parada por fonte incompleta.'],
            'falhas' => ['Nenhuma falha técnica', 'Nenhuma competência está parada por falha técnica.'],
            'satisfeitos' => ['Nenhuma competência satisfeita ainda', 'Nenhuma competência foi gerada ou encontrada pela automação até agora.'],
            'encerrados' => ['Nenhuma competência encerrada', 'Nenhuma competência saiu do perímetro da automação sem ser gerada.'],
            default => ['Nenhuma competência encontrada', 'Nenhuma competência corresponde aos filtros aplicados.'],
        };
    }
}
