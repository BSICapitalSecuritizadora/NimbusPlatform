<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Actions\Clients\AnalyzeClientSpreadsheet;
use App\Actions\Clients\ClientSpreadsheetAnalysis;
use App\Actions\Clients\ImportClientsFromSpreadsheet;
use App\Filament\Resources\Clients\ClientResource;
use App\Models\Client;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ListClients extends ListRecords
{
    public const TAB_ACTIVE = 'ativos';

    public const TAB_TRASHED = 'excluidos';

    public const TAB_ALL = 'todos';

    private const PREVIEW_LIMIT = 50;

    protected static string $resource = ClientResource::class;

    protected static ?string $title = 'Clientes';

    /**
     * @var array{path: string, analysis: ClientSpreadsheetAnalysis}|null
     */
    private ?array $memoizedAnalysis = null;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadTemplate')
                ->label('Baixar Modelo')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->tooltip('Baixar a planilha padrão de cadastro de clientes')
                ->url(fn (): string => route('admin.clients.template.download')),

            $this->importAction(),

            CreateAction::make()
                ->label('Novo Cliente')
                ->icon('heroicon-o-plus'),
        ];
    }

    /**
     * The resource query keeps soft deleted clients in reach, so the listing
     * needs an explicit switch: without it every tab would mix both. Excluded
     * from record resolution so an action can still reach a record that leaves
     * the current tab -- restoring, for instance.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $activeCount = Client::query()->count();
        $trashedCount = Client::onlyTrashed()->count();

        return [
            self::TAB_ACTIVE => Tab::make('Ativos')
                ->icon('heroicon-m-user-group')
                ->badge($activeCount)
                ->badgeColor('gray')
                ->excludeQueryWhenResolvingRecord()
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->withoutTrashed()),

            self::TAB_TRASHED => Tab::make('Excluídos')
                ->icon('heroicon-m-trash')
                ->badge($trashedCount)
                ->badgeColor($trashedCount > 0 ? 'danger' : 'gray')
                ->excludeQueryWhenResolvingRecord()
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->onlyTrashed()),

            self::TAB_ALL => Tab::make('Todos')
                ->icon('heroicon-m-queue-list')
                ->badge($activeCount + $trashedCount)
                ->badgeColor('gray')
                ->excludeQueryWhenResolvingRecord()
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->withTrashed()),
        ];
    }

    private function importAction(): Action
    {
        return Action::make('importClients')
            ->label('Importar Clientes')
            ->icon('heroicon-o-arrow-up-tray')
            ->color('primary')
            ->modalHeading('Importar Clientes')
            ->modalWidth(Width::FiveExtraLarge)
            ->modalSubmitActionLabel('Confirmar importação')
            ->visible(fn (): bool => ClientResource::canCreate())
            ->steps([
                Step::make('Arquivo')
                    ->description('Selecione a planilha preenchida')
                    ->schema([
                        FileUpload::make('file')
                            ->label('Planilha de Clientes (.xlsx)')
                            ->disk('local')
                            ->directory('imports/clients')
                            ->acceptedFileTypes([
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'text/csv',
                                'application/csv',
                            ])
                            ->required()
                            ->live()
                            ->helperText('Utilize a planilha padrão. CPF/CNPJ são validados com as mesmas regras do cadastro manual.'),
                    ]),

                Step::make('Conferência')
                    ->description('Revise antes de confirmar')
                    ->schema([
                        Placeholder::make('preview')
                            ->hiddenLabel()
                            ->content(fn (Get $get): Htmlable => $this->renderPreview($get('file'))),
                    ]),
            ])
            ->action(function (array $data): void {
                $path = $this->resolvePath($data['file'] ?? null);
                $analysis = $this->analyze($path);

                if (($analysis === null) || ! $analysis->canImport()) {
                    $notification = Notification::make()
                        ->danger()
                        ->title('Importação não realizada.')
                        ->body('Corrija as inconsistências apontadas na conferência e envie a planilha novamente.')
                        ->persistent();

                    // A document held by a deleted client is not a spreadsheet
                    // error to fix: the existing registration has to be
                    // restored, so the notification points at it.
                    if ((($analysis?->softDeletedCount() ?? 0) > 0) && ClientResource::canViewAny()) {
                        $notification
                            ->body('Há CPF/CNPJ pertencentes a clientes excluídos. Restaure os cadastros existentes em vez de criar novos, e corrija as demais inconsistências antes de reenviar a planilha.')
                            ->actions([
                                Action::make('verExcluidos')
                                    ->label('Ver clientes excluídos')
                                    ->url(self::trashedListingUrl()),
                            ]);
                    }

                    $notification->send();

                    return;
                }

                $result = app(ImportClientsFromSpreadsheet::class)->handle($analysis);

                // No personal data goes into the log: only counters and the
                // file name, alongside the causer recorded by the activity log.
                activity('importacao-clientes')
                    ->causedBy(auth()->user())
                    ->withProperties([
                        'arquivo' => basename((string) $path),
                        'clientes_cadastrados' => $result['clients'],
                        'pessoas_fisicas' => $result['individuals'],
                        'pessoas_juridicas' => $result['companies'],
                    ])
                    ->log('Importação de clientes concluída.');

                Notification::make()
                    ->success()
                    ->title('Importação concluída com sucesso.')
                    ->body(sprintf(
                        '%d clientes cadastrados. Pessoas físicas: %d. Pessoas jurídicas: %d.',
                        $result['clients'],
                        $result['individuals'],
                        $result['companies'],
                    ))
                    ->persistent()
                    ->send();
            });
    }

    private function renderPreview(mixed $file): Htmlable
    {
        $analysis = $this->analyze($this->resolvePath($file));

        if ($analysis === null) {
            return new HtmlString('<p class="fi-color-danger">Não foi possível ler a planilha enviada.</p>');
        }

        if ($analysis->fileErrors !== []) {
            return new HtmlString('<p class="fi-color-danger"><b>'.e(implode(' ', $analysis->fileErrors)).'</b></p>');
        }

        return new HtmlString($this->renderSummary($analysis).$this->renderTable($analysis));
    }

    private function renderSummary(ClientSpreadsheetAnalysis $analysis): string
    {
        $lines = [
            'Total de linhas: <b>'.$analysis->totalLines().'</b>',
            'Válidas: <b>'.$analysis->validCount().'</b>',
            'Com erro: <b>'.$analysis->errorCount().'</b>',
            'Já cadastrados: <b>'.$analysis->alreadyRegisteredCount().'</b>',
            'Cadastros excluídos: <b>'.$analysis->softDeletedCount().'</b>',
            'Duplicados na planilha: <b>'.$analysis->duplicatedInFileCount().'</b>',
        ];

        if ($analysis->emptyLineCount() > 0) {
            $lines[] = 'Linhas vazias ignoradas: <b>'.$analysis->emptyLineCount().'</b>';
        }

        $verdict = $analysis->canImport()
            ? '<p class="fi-color-success"><b>Planilha pronta para importação.</b></p>'
            : '<p class="fi-color-danger"><b>Corrija as inconsistências antes de confirmar. A importação só é liberada quando todas as linhas estiverem válidas.</b></p>';

        return '<div class="fi-ta-text-item-label">'.implode(' &nbsp;·&nbsp; ', $lines).'</div>'.$verdict.self::renderTrashedHint($analysis);
    }

    private function renderTable(ClientSpreadsheetAnalysis $analysis): string
    {
        $rows = $analysis->previewRows();

        $renderedRows = $rows->take(self::PREVIEW_LIMIT)->map(function (array $row): string {
            $status = ClientSpreadsheetAnalysis::statusLabel($row['status']);
            $message = filled($row['message'] ?? null) ? ' — '.e((string) $row['message']) : '';

            return '<tr>'
                .'<td style="padding:.25rem .5rem;">'.$row['line'].'</td>'
                .'<td style="padding:.25rem .5rem;">'.e((string) $row['type']).'</td>'
                .'<td style="padding:.25rem .5rem;">'.e((string) $row['name']).'</td>'
                .'<td style="padding:.25rem .5rem;">'.e((string) $row['document']).'</td>'
                .'<td style="padding:.25rem .5rem;">'.e($status).$message.self::renderRestoreLink($row).'</td>'
                .'</tr>';
        })->implode('');

        $omitted = $rows->count() > self::PREVIEW_LIMIT
            ? '<p>Exibindo as primeiras '.self::PREVIEW_LIMIT.' de '.$rows->count().' linhas.</p>'
            : '';

        return '<div style="overflow-x:auto;"><table style="width:100%;font-size:.875rem;">'
            .'<thead><tr>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Linha</th>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Tipo</th>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Cliente</th>'
            .'<th style="text-align:left;padding:.25rem .5rem;">CPF/CNPJ</th>'
            .'<th style="text-align:left;padding:.25rem .5rem;">Status</th>'
            .'</tr></thead><tbody>'.$renderedRows.'</tbody></table></div>'.$omitted;
    }

    /**
     * Shortcut to the deleted clients tab, so a blocked import leads straight to
     * where the existing registration can be restored.
     */
    private static function renderTrashedHint(ClientSpreadsheetAnalysis $analysis): string
    {
        if (($analysis->softDeletedCount() === 0) || ! ClientResource::canViewAny()) {
            return '';
        }

        return '<p><a href="'.e(self::trashedListingUrl()).'" class="fi-link fi-color-primary" style="text-decoration:underline;">'
            .'Ver os cadastros excluídos e restaurá-los</a></p>';
    }

    /**
     * Link to the client that already holds the document. The restore itself
     * lives on that page, behind the clients.restore permission.
     *
     * @param  array<string, mixed>  $row
     */
    private static function renderRestoreLink(array $row): string
    {
        if (($row['status'] ?? null) !== AnalyzeClientSpreadsheet::STATUS_SOFT_DELETED) {
            return '';
        }

        $clientId = $row['existing_client_id'] ?? null;

        if (blank($clientId) || ! ClientResource::canViewAny()) {
            return '';
        }

        $url = ClientResource::getUrl('view', ['record' => $clientId]);

        return ' <a href="'.e($url).'" class="fi-link fi-color-primary" style="text-decoration:underline;">Restaurar cadastro</a>';
    }

    public static function trashedListingUrl(): string
    {
        return ClientResource::getUrl('index', ['tab' => self::TAB_TRASHED]);
    }

    private function analyze(?string $path): ?ClientSpreadsheetAnalysis
    {
        if (blank($path) || ! is_file($path)) {
            return null;
        }

        if (($this->memoizedAnalysis['path'] ?? null) === $path) {
            return $this->memoizedAnalysis['analysis'];
        }

        $analysis = app(AnalyzeClientSpreadsheet::class)->handle($path);

        $this->memoizedAnalysis = ['path' => $path, 'analysis' => $analysis];

        return $analysis;
    }

    private function resolvePath(mixed $file): ?string
    {
        if (is_array($file)) {
            $file = collect($file)->first();
        }

        if ($file instanceof TemporaryUploadedFile) {
            return $file->getRealPath();
        }

        if (! is_string($file) || ($file === '')) {
            return null;
        }

        return Storage::disk('local')->path($file);
    }
}
