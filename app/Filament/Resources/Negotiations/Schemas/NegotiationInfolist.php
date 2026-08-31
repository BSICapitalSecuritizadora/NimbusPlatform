<?php

namespace App\Filament\Resources\Negotiations\Schemas;

use App\Models\Negotiation;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class NegotiationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dados da Negociação')
                ->description('Operação, empreendimento e competência vinculados ao lançamento.')
                ->icon('heroicon-o-building-office')
                ->columnSpanFull()
                ->columns(['default' => 1, 'sm' => 1, 'md' => 3])
                ->schema([
                    TextEntry::make('emission.name')
                        ->label('Operação')
                        ->weight('semibold')
                        ->icon('heroicon-m-briefcase')
                        ->placeholder('—'),

                    TextEntry::make('construction.development_name')
                        ->label('Empreendimento')
                        ->weight('semibold')
                        ->icon('heroicon-m-building-office-2')
                        ->placeholder('—'),

                    TextEntry::make('reference_month')
                        ->label('Competência')
                        ->state(fn (Negotiation $record): string => $record->formatted_reference_month ?: Negotiation::formatReferenceMonthForDisplay($record->reference_month))
                        ->weight('semibold')
                        ->icon('heroicon-m-calendar')
                        ->placeholder('—'),
                ]),

            Section::make('Negociações do Mês')
                ->description('Movimentações comerciais registradas nesta competência.')
                ->icon('heroicon-o-chart-bar')
                ->columnSpanFull()
                ->schema([
                    ViewEntry::make('monthly_indicators')
                        ->hiddenLabel()
                        ->view('filament.infolists.negotiation-monthly-indicators')
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
