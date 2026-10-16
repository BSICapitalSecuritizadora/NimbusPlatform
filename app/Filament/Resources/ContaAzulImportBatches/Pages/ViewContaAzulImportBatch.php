<?php

namespace App\Filament\Resources\ContaAzulImportBatches\Pages;

use App\Exceptions\Funds\ContaAzulReconciliationException;
use App\Filament\Resources\ContaAzulImportBatches\ContaAzulImportBatchResource;
use App\Models\ContaAzulImportBatch;
use App\Services\Funds\ContaAzulStaleBatchRecoveryService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewContaAzulImportBatch extends ViewRecord
{
    protected static string $resource = ContaAzulImportBatchResource::class;

    protected static ?string $breadcrumb = 'Visualizar';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page',
    ];

    public function getTitle(): string
    {
        /** @var ContaAzulImportBatch $record */
        $record = $this->getRecord();

        return 'Importação #'.$record->getKey();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('recover')
                ->label('Recuperar lote interrompido')
                ->icon('heroicon-o-lifebuoy')
                ->color('warning')
                ->visible(fn (ContaAzulImportBatch $record): bool => $record->status === ContaAzulImportBatch::STATUS_RUNNING
                    && (auth()->user()?->can(ContaAzulStaleBatchRecoveryService::PERMISSION_RECOVER) ?? false))
                ->modalHeading('Recuperar lote interrompido')
                ->modalDescription(fn (ContaAzulImportBatch $record): string => $this->recoveryDescription($record))
                ->form([
                    Textarea::make('reason')
                        ->label('Motivo da recuperação')
                        ->required()
                        ->minLength(ContaAzulStaleBatchRecoveryService::MIN_REASON_LENGTH)
                        ->rows(3)
                        ->maxLength(2000)
                        ->helperText('Descreva a evidência do abandono (processo encerrado, servidor reiniciado, etc.).'),
                ])
                ->action(function (ContaAzulImportBatch $record, array $data): void {
                    try {
                        app(ContaAzulStaleBatchRecoveryService::class)->recover(auth()->user(), $record, $data['reason']);

                        Notification::make()
                            ->title('Lote marcado como abandonado. Os títulos preservados aguardam nova tentativa.')
                            ->success()
                            ->send();
                    } catch (ContaAzulReconciliationException $exception) {
                        Notification::make()
                            ->title('Recuperação recusada.')
                            ->body($exception->getMessage())
                            ->danger()
                            ->persistent()
                            ->send();
                    }
                }),
        ];
    }

    private function recoveryDescription(ContaAzulImportBatch $record): string
    {
        $timeout = app(ContaAzulStaleBatchRecoveryService::class)->staleTimeoutMinutes();

        if ($record->isStale($timeout)) {
            return "Sem atividade há mais de {$timeout} minutos: provável abandono. A recuperação marca o lote como abandonado sem apagar nenhum título, liberando nova tentativa que referencia este lote.";
        }

        return 'Este lote apresenta atividade recente e pode estar em execução — a recuperação será recusada. Prossiga somente se houver evidência externa de abandono.';
    }
}
