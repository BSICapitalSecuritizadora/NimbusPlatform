<?php

namespace App\Filament\Resources\SalesBoardAutomationTargets\Schemas;

use App\Enums\SalesBoardAutomationSatisfiedVia;
use App\Enums\SalesBoardAutomationTargetStatus;
use App\Models\SalesBoardAutomationTarget;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * O detalhe de uma competência sob automação.
 *
 * A mensagem de falha exibida é a sanitizada -- a mesma que foi persistida. O
 * rastreamento técnico fica no log da aplicação, que tem controle de acesso
 * próprio; jogá-lo numa tela de operação espalharia detalhe de infraestrutura
 * por um lugar que ninguém trata como sensível.
 */
class SalesBoardAutomationTargetInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Competência')
                ->columns(3)
                ->schema([
                    TextEntry::make('construction.development_name')->label('Empreendimento'),
                    TextEntry::make('reference_month')
                        ->label('Competência')
                        ->formatStateUsing(fn (SalesBoardAutomationTarget $record): string => $record->referenceMonthLabel()),
                    TextEntry::make('due_date')->label('Devida desde')->date('d/m/Y'),
                ]),

            Section::make('Situação')
                ->columns(3)
                ->schema([
                    TextEntry::make('status')
                        ->label('Situação')
                        ->badge()
                        ->formatStateUsing(fn (SalesBoardAutomationTargetStatus $state): string => $state->label())
                        ->color(fn (SalesBoardAutomationTargetStatus $state): string => $state->color()),
                    TextEntry::make('satisfied_via')
                        ->label('Satisfeito por')
                        ->placeholder('—')
                        ->formatStateUsing(fn (?SalesBoardAutomationSatisfiedVia $state): string => $state?->label() ?? '—'),
                    TextEntry::make('attempt_count')->label('Tentativas'),
                    TextEntry::make('first_attempt_at')->label('Primeira tentativa')->dateTime('d/m/Y H:i')->placeholder('—'),
                    TextEntry::make('last_attempt_at')->label('Última tentativa')->dateTime('d/m/Y H:i')->placeholder('—'),
                    TextEntry::make('next_attempt_at')->label('Próxima tentativa')->dateTime('d/m/Y H:i')->placeholder('—'),
                ]),

            Section::make('Motivo da parada')
                ->visible(fn (SalesBoardAutomationTarget $record): bool => $record->currentReason() !== null)
                ->schema([
                    TextEntry::make('last_blocker_codes')
                        ->label('Códigos')
                        ->placeholder('—')
                        ->formatStateUsing(fn (SalesBoardAutomationTarget $record): string => implode(', ', $record->blockerCodes()) ?: '—'),
                    TextEntry::make('reason')
                        ->label('Descrição')
                        ->state(fn (SalesBoardAutomationTarget $record): string => (string) $record->currentReason())
                        ->columnSpanFull(),
                ]),

            Section::make('Ciclo')
                ->visible(fn (SalesBoardAutomationTarget $record): bool => $record->sales_board_cycle_id !== null)
                ->columns(2)
                ->schema([
                    TextEntry::make('cycle.id')->label('Ciclo')->formatStateUsing(fn (mixed $state): string => '#'.$state),
                    TextEntry::make('cycle.status')
                        ->label('Situação do ciclo')
                        ->formatStateUsing(fn (mixed $state): string => $state?->label() ?? '—'),
                ]),
        ]);
    }
}
