<?php

namespace App\Filament\Resources\Receivables\Pages;

use App\Actions\Receivables\ImportReceivablesFromSpreadsheet;
use App\Filament\Resources\Receivables\ReceivableResource;
use App\Models\Emission;
use App\Models\Receivable;
use App\Rules\ReceivablesSpreadsheetFile;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ListReceivables extends ListRecords
{
    protected static string $resource = ReceivableResource::class;

    protected static ?string $title = 'Recebíveis';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('import')
                ->label('Importar Planilha')
                ->icon('heroicon-o-arrow-up-tray')
                ->modalHeading('Importar resumo de recebíveis')
                ->modalWidth('2xl')
                ->form([
                    Select::make('emission_id')
                        ->label('Emissao')
                        ->options(fn (): array => Emission::query()
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->preload()
                        ->required()
                        ->validationMessages([
                            'required' => 'Selecione a emissao para vincular os recebiveis importados.',
                        ]),
                    FileUpload::make('file')
                        ->label('Arquivo Excel (.xlsx)')
                        ->disk('local')
                        ->directory('imports/receivables')
                        ->rules([new ReceivablesSpreadsheetFile])
                        ->helperText('A competencia e os indicadores serao lidos somente da aba Resumo.')
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $emission = Emission::query()->findOrFail($data['emission_id']);
                    $path = $this->resolveUploadedSpreadsheetPath($data['file'] ?? null);

                    try {
                        $result = app(ImportReceivablesFromSpreadsheet::class)->handle($path, $emission);
                    } catch (ValidationException $exception) {
                        throw $exception;
                    } catch (\Throwable $exception) {
                        report($exception);

                        Notification::make()
                            ->title('Erro ao ler a planilha')
                            ->body('Nao foi possivel processar o arquivo informado.')
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Importacao concluida')
                        ->body('O resumo da competencia '.Receivable::formatReferenceMonthForDisplay($result['reference_month']).' foi importado ou atualizado com sucesso.')
                        ->success()
                        ->send();
                }),
            CreateAction::make()
                ->label('Criar resumo'),
        ];
    }

    protected function resolveUploadedSpreadsheetPath(mixed $file): string
    {
        $file = is_array($file) ? Arr::first($file) : $file;

        if (($file instanceof TemporaryUploadedFile) || ($file instanceof UploadedFile)) {
            $realPath = $file->getRealPath();

            if (is_string($realPath) && is_file($realPath)) {
                return $realPath;
            }
        }

        if (is_string($file)) {
            if (Storage::disk('local')->exists($file)) {
                return Storage::disk('local')->path($file);
            }

            if (is_file($file)) {
                return $file;
            }
        }

        throw ValidationException::withMessages([
            'file' => ['Nao foi possivel localizar o arquivo enviado. Envie a planilha novamente.'],
        ]);
    }
}
