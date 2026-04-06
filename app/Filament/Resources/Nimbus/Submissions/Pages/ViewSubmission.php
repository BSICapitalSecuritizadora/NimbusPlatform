<?php

namespace App\Filament\Resources\Nimbus\Submissions\Pages;

use App\Filament\Resources\Nimbus\Submissions\SubmissionResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewSubmission extends ViewRecord
{
    protected static string $resource = SubmissionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('reenviar_notificacao')
                ->label('Reenviar Notificação')
                ->icon('heroicon-o-envelope')
                ->color('gray')
                ->requiresConfirmation()
                ->action(function () {
                    // TODO: Implement resend logic
                }),
            Actions\Action::make('alterar_situacao')
                ->label('Alterar Situação')
                ->icon('heroicon-o-arrow-path')
                ->form([
                    \Filament\Forms\Components\Select::make('status')
                        ->label('Nova Situação')
                        ->options([
                            'Em Análise' => 'Em Análise',
                            'Aprovado' => 'Aprovado',
                            'Rejeitado' => 'Rejeitado',
                        ])
                        ->required(),
                ])
                ->action(function (array $data, $record) {
                    // Update situation in real environment depending on columns
                }),
            Actions\Action::make('print')
                ->label('Imprimir')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->url('#')
                ->openUrlInNewTab(),
        ];
    }
}
