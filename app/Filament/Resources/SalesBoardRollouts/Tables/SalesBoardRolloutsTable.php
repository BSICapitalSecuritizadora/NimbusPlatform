<?php

namespace App\Filament\Resources\SalesBoardRollouts\Tables;

use App\Enums\SalesBoardRolloutHomologationStatus;
use App\Enums\SalesBoardSource;
use App\Filament\Resources\SalesBoardRollouts\Pages\ManageSalesBoardRollout;
use App\Models\Emission;
use App\Services\SalesBoards\DatabaseSalesBoardAutomationEligibilityProvider;
use App\Support\SalesBoards\SalesBoardAutomationConfig;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * A lista de Emissões e o modo de cada uma.
 *
 * A coluna de situação responde a pergunta operacional inteira numa etiqueta:
 * legado, em homologação, homologada, automatizada -- e, o caso que mais
 * confunde, automatizada **mas suspensa** porque o escopo mudou. Sem essa
 * última, uma Emissão automatizada que parou de produzir competências pareceria
 * apenas um mês silencioso.
 */
class SalesBoardRolloutsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Emissão')
                    ->searchable()
                    ->wrap(),

                TextColumn::make('sales_board_source')
                    ->label('Modo do Quadro')
                    ->badge()
                    ->formatStateUsing(fn (SalesBoardSource $state): string => $state->label())
                    ->color(fn (SalesBoardSource $state): string => $state->color()),

                TextColumn::make('situation')
                    ->label('Situação')
                    ->badge()
                    ->state(fn (Emission $record): string => self::situation($record))
                    ->color(fn (string $state): string => match ($state) {
                        'Automação suspensa' => 'danger',
                        'Automatizada' => 'success',
                        'Homologação aprovada' => 'info',
                        'Em homologação' => 'warning',
                        default => 'gray',
                    })
                    /**
                     * "Automatizada" com o interruptor global desligado não
                     * produz nada, e a etiqueta sozinha fingiria o contrário.
                     * Lê só o modo já carregado e a configuração: nenhuma
                     * consulta a mais por linha.
                     */
                    ->description(fn (Emission $record): ?string => ($record->usesAutomatedSalesBoard() && ! SalesBoardAutomationConfig::enabled())
                        ? 'Automação global desligada: nada é processado.'
                        : null),

                TextColumn::make('sales_board_automation_start_reference_month')
                    ->label('Competência inicial')
                    ->formatStateUsing(fn (mixed $state): string => $state?->format('m/Y') ?? '—')
                    ->placeholder('—'),

                TextColumn::make('constructions_count')
                    ->label('Empreendimentos')
                    ->alignRight(),

                TextColumn::make('sales_board_auto_open_builder_review')
                    ->label('Abre validação')
                    ->formatStateUsing(fn (mixed $state): string => $state ? 'Sim' : 'Não')
                    ->visibleFrom('lg'),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('sales_board_source')
                    ->label('Modo')
                    ->options(collect(SalesBoardSource::cases())
                        ->mapWithKeys(fn (SalesBoardSource $case): array => [$case->value => $case->label()])
                        ->all()),
            ])
            ->recordActions([
                Action::make('manage')
                    ->label('Abrir')
                    ->icon('heroicon-o-arrow-right-circle')
                    ->url(fn (Emission $record): string => ManageSalesBoardRollout::getUrl(['record' => $record])),
            ])
            ->toolbarActions([])
            ->emptyStateHeading('Nenhuma Emissão encontrada')
            ->emptyStateDescription('Não há Emissões cadastradas neste modo, ou nenhuma corresponde aos filtros aplicados.');
    }

    /**
     * A situação operacional, em uma etiqueta.
     */
    private static function situation(Emission $emission): string
    {
        if ($emission->usesAutomatedSalesBoard()) {
            return app(DatabaseSalesBoardAutomationEligibilityProvider::class)->hasScopeDrift($emission)
                ? 'Automação suspensa'
                : 'Automatizada';
        }

        $latest = $emission->salesBoardRolloutHomologations()->first();

        return match ($latest?->status) {
            SalesBoardRolloutHomologationStatus::Draft => 'Em homologação',
            SalesBoardRolloutHomologationStatus::Approved => 'Homologação aprovada',
            default => 'Modo legado',
        };
    }
}
