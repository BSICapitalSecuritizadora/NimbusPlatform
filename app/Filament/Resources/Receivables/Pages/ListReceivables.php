<?php

namespace App\Filament\Resources\Receivables\Pages;

use App\Actions\Receivables\ImportReceivablesFromSpreadsheet;
use App\Filament\Resources\Receivables\ReceivableResource;
use App\Models\Emission;
use App\Models\Receivable;
use App\Rules\ReceivablesSpreadsheetFile;
use App\Support\Uploads\LocalUploadedFile;
use Closure;
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

class ListReceivables extends ListRecords
{
    protected static string $resource = ReceivableResource::class;

    /**
     * Diretório onde o campo de arquivo grava a planilha enviada.
     */
    private const STORED_SPREADSHEET_DIRECTORY = 'imports/receivables';

    protected static ?string $title = 'Recebíveis';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-receivables-list-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Acompanhe carteira, amortizações, inadimplência e movimentações dos recebíveis por competência.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('import')
                ->label('Importar planilha')
                ->color('gray')
                ->icon('heroicon-o-arrow-up-tray')
                ->modalHeading('Importar resumo de recebíveis')
                ->modalWidth('2xl')
                /**
                 * Cria ou reescreve o resumo da competência, então exige criar e
                 * editar ({@see ReceivableResource::canImport()}). O Filament
                 * confere o `visible()` de novo no servidor, quando a ação monta
                 * e quando executa: o mount forjado por quem só vê não abre nada.
                 */
                ->visible(fn (): bool => ReceivableResource::canImport())
                ->form([
                    Select::make('emission_id')
                        ->label('Emissão')
                        ->options(fn (): array => Emission::query()
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->preload()
                        ->required()
                        ->validationMessages([
                            'required' => 'Selecione a operação para vincular os recebíveis importados.',
                        ]),
                    FileUpload::make('file')
                        ->label('Arquivo Excel (.xlsx)')
                        ->disk('local')
                        ->directory(self::STORED_SPREADSHEET_DIRECTORY)
                        ->acceptedFileTypes((array) config('uploads.receivables_import.allowed_mimes', []))
                        ->rules([new ReceivablesSpreadsheetFile])
                        ->helperText('A competência e os indicadores serão lidos da aba "Resumo". Caso ela não exista, o sistema tentará as abas "Planilha1" ou "Plan1".')
                        ->required(),
                ])
                ->action(function (array $data, Action $action): void {
                    $emission = Emission::query()->findOrFail($data['emission_id']);

                    try {
                        $result = $this->withUploadedSpreadsheet(
                            $data['file'] ?? null,
                            fn (string $path): array => app(ImportReceivablesFromSpreadsheet::class)->handle($path, $emission),
                        );
                    } catch (ValidationException $exception) {
                        $this->notifyImportValidationFailure($exception);

                        $action->halt();

                        return;
                    } catch (\Throwable $exception) {
                        report($exception);

                        $this->notifyImportReadFailure();

                        $action->halt();

                        return;
                    }

                    Notification::make()
                        ->title('Importação concluída com sucesso!')
                        ->body('O resumo da competência '.Receivable::formatReferenceMonthForDisplay($result['reference_month']).' foi importado ou atualizado.')
                        ->success()
                        ->send();
                }),
            CreateAction::make()
                ->label('Cadastrar resumo')
                ->icon('heroicon-m-plus')
                ->color('primary'),
        ];
    }

    /**
     * Executa a importação com um caminho local legível da planilha enviada.
     *
     * O envio vai por {@see LocalUploadedFile}: com o temporário num disco
     * remoto, `getRealPath()` era relativo e a planilha não era encontrada.
     *
     * @template TReturn
     *
     * @param  Closure(string): TReturn  $callback
     * @return TReturn
     */
    protected function withUploadedSpreadsheet(mixed $file, Closure $callback): mixed
    {
        $file = is_array($file) ? Arr::first($file) : $file;

        if ($file instanceof UploadedFile) {
            return LocalUploadedFile::using($file, $callback);
        }

        return $callback($this->resolveUploadedSpreadsheetPath($file));
    }

    /**
     * O caminho físico da planilha que o campo gravou no disco privado.
     *
     * O valor do campo vem do cliente, como todo estado de formulário Livewire.
     * Por isso só é aceito um caminho relativo dentro de
     * {@see self::STORED_SPREADSHEET_DIRECTORY}, sem `..`: antes, um caminho
     * absoluto qualquer era lido como planilha.
     */
    protected function resolveUploadedSpreadsheetPath(mixed $file): string
    {
        if (is_string($file) && self::isStoredSpreadsheetPath($file) && Storage::disk('local')->exists($file)) {
            return Storage::disk('local')->path($file);
        }

        throw ValidationException::withMessages([
            'file' => ['Não foi possível localizar o arquivo enviado. Por favor, envie a planilha novamente.'],
        ]);
    }

    private static function isStoredSpreadsheetPath(string $file): bool
    {
        if (! str_starts_with($file, self::STORED_SPREADSHEET_DIRECTORY.'/')) {
            return false;
        }

        foreach (explode('/', $file) as $segment) {
            if (in_array($segment, ['', '.', '..'], true)) {
                return false;
            }
        }

        return ! str_contains($file, '\\');
    }

    protected function formatImportValidationErrors(ValidationException $exception): string
    {
        $messages = collect($exception->errors())
            ->flatten()
            ->filter(fn (mixed $message): bool => filled($message))
            ->map(fn (mixed $message): string => trim((string) $message))
            ->values();

        if ($messages->isEmpty()) {
            return 'Não foi possível validar a planilha enviada.';
        }

        return $messages->implode(PHP_EOL);
    }

    protected function notifyImportValidationFailure(ValidationException $exception): void
    {
        Notification::make()
            ->title('Importação não realizada')
            ->body($this->formatImportValidationErrors($exception))
            ->danger()
            ->persistent()
            ->send();
    }

    protected function notifyImportReadFailure(): void
    {
        Notification::make()
            ->title('Erro ao ler a planilha')
            ->body('Não foi possível processar o arquivo informado.')
            ->danger()
            ->persistent()
            ->send();
    }
}
