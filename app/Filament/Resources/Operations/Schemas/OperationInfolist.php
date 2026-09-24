<?php

namespace App\Filament\Resources\Operations\Schemas;

use App\Enums\OperationStatus;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OperationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Operação')
                ->extraAttributes(['class' => 'bsi-op-details'])
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('code')->label('Código'),
                    TextEntry::make('title')->label('Título'),
                    TextEntry::make('emission.name')->label('Emissão'),
                    TextEntry::make('status')
                        ->label('Situação')
                        ->badge()
                        ->extraAttributes(['class' => 'bsi-op-status'])
                        ->formatStateUsing(fn (OperationStatus $state): string => $state->label())
                        ->color(fn (OperationStatus $state): string => $state->color()),
                    TextEntry::make('due_date')
                        ->label('Vencimento')
                        ->date('d/m/Y')
                        ->placeholder('—')
                        ->extraAttributes(['class' => 'bsi-op-date']),
                    TextEntry::make('next_pending_measurement_at')
                        ->label('Próxima Medição')
                        ->date('m/Y')
                        ->placeholder('—')
                        ->extraAttributes(['class' => 'bsi-op-date']),
                    TextEntry::make('planSets.construction.development_name')
                        ->label('Empreendimentos')
                        ->badge()
                        ->placeholder('—')
                        ->columnSpanFull()
                        ->extraAttributes(['class' => 'bsi-op-developments']),
                ])
                ->columns(['default' => 1, 'md' => 2, 'lg' => 3]),

            Section::make('Responsáveis')
                ->extraAttributes(['class' => 'bsi-op-responsibles'])
                ->columnSpanFull()
                ->collapsible()
                ->schema([
                    TextEntry::make('responsibleUser.name')->label('Etapa 1 — Engenharia')->placeholder('—'),
                    TextEntry::make('stage2Reviewer.name')->label('Etapa 2 — Gestão')->placeholder('—'),
                    TextEntry::make('stage3Reviewer.name')->label('Etapa 3 — Jurídico/Risco')->placeholder('—'),
                    TextEntry::make('paymentManager.name')->label('Pagamentos')->placeholder('—'),
                    TextEntry::make('paymentReceiptUploader.name')
                        ->label('Comprovantes')
                        ->placeholder('Não configurado — somente admin/super-admin pode enviar'),
                    TextEntry::make('paymentFinalizer.name')->label('Finalizador')->placeholder('—'),
                    TextEntry::make('assignedUser.name')
                        ->label('Responsável Geral')
                        ->placeholder('—')
                        ->columnSpanFull()
                        ->extraAttributes(['class' => 'bsi-op-general-manager']),
                ])
                ->columns(['default' => 1, 'md' => 2, 'lg' => 3]),
        ]);
    }
}
