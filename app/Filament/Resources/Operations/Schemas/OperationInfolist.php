<?php

namespace App\Filament\Resources\Operations\Schemas;

use App\Models\Operation;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OperationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Operação')
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('code')->label('Código'),
                    TextEntry::make('title')->label('Título'),
                    TextEntry::make('emission.name')->label('Emissão'),
                    TextEntry::make('planSets.construction.development_name')->label('Empreendimentos')->badge()->placeholder('—'),
                    TextEntry::make('status')
                        ->label('Situação')
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => Operation::STATUS_OPTIONS[$state] ?? $state),
                    TextEntry::make('due_date')->label('Vencimento')->date('d/m/Y')->placeholder('—'),
                    TextEntry::make('next_measurement_at')->label('Próxima Medição')->date('d/m/Y')->placeholder('—'),
                ])
                ->columns(3),

            Section::make('Responsáveis')
                ->columnSpanFull()
                ->collapsible()
                ->schema([
                    TextEntry::make('responsibleUser.name')->label('Etapa 1 — Engenharia')->placeholder('—'),
                    TextEntry::make('stage2Reviewer.name')->label('Etapa 2 — Gestão')->placeholder('—'),
                    TextEntry::make('stage3Reviewer.name')->label('Etapa 3 — Jurídico/Risco')->placeholder('—'),
                    TextEntry::make('paymentManager.name')->label('Pagamentos')->placeholder('—'),
                    TextEntry::make('paymentFinalizer.name')->label('Finalizador')->placeholder('—'),
                    TextEntry::make('assignedUser.name')->label('Responsável Geral')->placeholder('—'),
                ])
                ->columns(3),
        ]);
    }
}
