<?php

namespace App\Filament\Resources\Emissions\EmissionResource\RelationManagers;

use App\Actions\Emissions\ImportPaymentsFromSpreadsheet;
use App\Actions\Emissions\PaymentSpreadsheetTemplate;
use App\Actions\Emissions\RemovePaymentFromSchedule;
use App\Enums\AccessPermission;
use App\Enums\PaymentRemovalOutcome;
use App\Filament\Pages\Settings as SettingsPage;
use App\Models\Payment;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\Exceptions\ActionNotResolvableException;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $recordTitleAttribute = 'payment_date';

    protected static ?string $title = 'Cronograma de Pagamentos';

    protected static ?string $modelLabel = 'Pagamento';

    protected static ?string $pluralModelLabel = 'Pagamentos';

    /**
     * O Filament tenta resolver de novo a ação que não achou o registro ao
     * desmontá-la; sem isto o aviso sairia duas vezes na mesma requisição.
     */
    protected bool $hasWarnedUnavailableScheduleDate = false;

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                DatePicker::make('payment_date')
                    ->label('Data de Pagamento')
                    ->required(),
                TextInput::make('premium_value')
                    ->label('Valor do Prêmio')
                    ->prefix('R$')
                    ->numeric()
                    ->default(0)
                    ->required()
                    ->placeholder('0,00'),
                TextInput::make('interest_value')
                    ->label('Valor dos Juros')
                    ->prefix('R$')
                    ->numeric()
                    ->default(0)
                    ->required()
                    ->placeholder('0,00'),
                TextInput::make('amortization_value')
                    ->label('Valor da Amortização')
                    ->prefix('R$')
                    ->numeric()
                    ->default(0)
                    ->required()
                    ->placeholder('0,00'),
                TextInput::make('extra_amortization_value')
                    ->label('Amortização Extraordinária')
                    ->prefix('R$')
                    ->numeric()
                    ->default(0)
                    ->required()
                    ->placeholder('0,00'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('payment_date')
            ->searchPlaceholder('Buscar pagamentos...')
            ->columns([
                TextColumn::make('payment_date')
                    ->label('Data de Pagamento')
                    ->date('d/m/Y')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('premium_value')
                    ->label('Prêmio')
                    ->money('BRL')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('interest_value')
                    ->label('Juros')
                    ->money('BRL')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('amortization_value')
                    ->label('Amortização')
                    ->money('BRL')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('extra_amortization_value')
                    ->label('Amortização Extra')
                    ->money('BRL')
                    ->alignEnd()
                    ->sortable(),
            ])
            ->defaultSort('payment_date', 'desc')
            ->filters([
                //
            ])
            ->headerActions([
                Action::make('download_template')
                    ->label('Download do Template')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->tooltip('Baixar modelo de planilha para preenchimento')
                    ->url(fn (): string => route('admin.payments.template.download'))
                    ->visible(fn (): bool => app(PaymentSpreadsheetTemplate::class)->exists()),
                Action::make('manage_template')
                    ->label('Configurar Template')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->color('gray')
                    ->tooltip('Configurar mapeamento de colunas do template')
                    ->url(fn (): string => SettingsPage::getUrl(panel: 'admin'))
                    ->visible(fn (): bool => auth()->user()?->can('settings.view') ?? false),
                Action::make('import')
                    ->label('Importar Dados')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('primary')
                    ->tooltip('Importar pagamentos via planilha (.xlsx / .csv)')
                    ->modalHeading('Importar Planilha de Pagamentos')
                    ->modalSubmitActionLabel('Importar Dados')
                    ->form([
                        FileUpload::make('file')
                            ->label('Planilha de Pagamentos (.xlsx / .csv)')
                            ->disk('local')
                            ->directory('imports')
                            ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/csv', 'text/csv'])
                            ->required(),
                    ])
                    ->action(function (array $data, RelationManager $livewire): void {
                        $path = Storage::disk('local')->path($data['file']);

                        try {
                            $count = app(ImportPaymentsFromSpreadsheet::class)->handle($path, $livewire->ownerRecord);
                        } catch (\Throwable) {
                            Notification::make()
                                ->title('Erro ao processar o arquivo')
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Importação concluída com sucesso!')
                            ->body("{$count} pagamentos foram processados.")
                            ->success()
                            ->send();
                    }),
                CreateAction::make()
                    ->label('Novo Pagamento')
                    ->icon('heroicon-m-plus')
                    ->tooltip('Lançar pagamento individual'),
            ])
            ->actions([
                EditAction::make(),
                $this->makeRemoveScheduleDateAction(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->authorize(fn (): bool => (! $this->isReadOnly()) && $this->canRemoveScheduleDates()),
                ]),
            ])
            ->emptyStateIcon('heroicon-o-calendar-days')
            ->emptyStateHeading('Nenhum pagamento cadastrado')
            ->emptyStateDescription('Importe uma planilha (.xlsx / .csv) ou cadastre manualmente para compor o cronograma desta emissão.')
            ->emptyStateActions([
                Action::make('empty_import')
                    ->label('Importar Dados')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('primary')
                    ->modalHeading('Importar Planilha de Pagamentos')
                    ->modalSubmitActionLabel('Importar Dados')
                    ->form([
                        FileUpload::make('file')
                            ->label('Planilha de Pagamentos (.xlsx / .csv)')
                            ->disk('local')
                            ->directory('imports')
                            ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/csv', 'text/csv'])
                            ->required(),
                    ])
                    ->action(function (array $data, RelationManager $livewire): void {
                        $path = Storage::disk('local')->path($data['file']);

                        try {
                            $count = app(ImportPaymentsFromSpreadsheet::class)->handle($path, $livewire->ownerRecord);
                        } catch (\Throwable) {
                            Notification::make()
                                ->title('Erro ao processar o arquivo')
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Importação concluída com sucesso!')
                            ->body("{$count} pagamentos foram processados.")
                            ->success()
                            ->send();
                    }),
                CreateAction::make('empty_create')
                    ->label('Cadastrar Pagamento')
                    ->icon('heroicon-m-plus')
                    ->color('gray'),
            ]);
    }

    /**
     * Remove uma única data do cronograma, sempre pela chave do registro.
     *
     * A lixeira aparece também na página de visualização, onde o painel deixa o
     * RelationManager somente leitura: o `authorize()` explícito substitui a
     * checagem padrão apenas nesta ação -- criar e editar continuam fora dali.
     * O atalho `mod+d` do DeleteAction sai porque, repetido em cada linha,
     * abriria a confirmação de todas as datas da página ao mesmo tempo.
     */
    protected function makeRemoveScheduleDateAction(): DeleteAction
    {
        return DeleteAction::make()
            ->label('Remover data')
            ->tooltip('Remover data')
            ->icon('heroicon-o-trash')
            ->iconButton()
            ->keyBindings(null)
            ->authorize(fn (): bool => $this->canRemoveScheduleDates())
            ->modalHeading('Remover data do cronograma')
            ->modalDescription(fn (Payment $record): string => "Deseja realmente remover a data {$record->payment_date?->format('d/m/Y')} do cronograma de pagamentos?")
            ->modalContent(fn (Payment $record): View => view('filament.emissions.payment-removal-summary', [
                'payment' => $record,
            ]))
            ->modalSubmitActionLabel('Remover')
            ->schema([
                Hidden::make('confirmed_snapshot'),
            ])
            ->fillForm(fn (Payment $record): array => [
                'confirmed_snapshot' => RemovePaymentFromSchedule::snapshot($record),
            ])
            ->successNotificationTitle(PaymentRemovalOutcome::Removed->label())
            ->failureNotificationTitle('Não foi possível remover a data do cronograma.')
            ->action(function (DeleteAction $action, Payment $record, array $data, RemovePaymentFromSchedule $removePayment): void {
                try {
                    $outcome = $removePayment->handle(
                        $this->getOwnerRecord(),
                        $record->getKey(),
                        (string) ($data['confirmed_snapshot'] ?? ''),
                    );
                } catch (\Throwable $exception) {
                    report($exception);

                    $action->failure();

                    return;
                }

                $this->keepTablePageWithinRange();

                if ($outcome === PaymentRemovalOutcome::Removed) {
                    $action->success();

                    return;
                }

                Notification::make()
                    ->title($outcome->label())
                    ->body($outcome === PaymentRemovalOutcome::Changed
                        ? 'Nada foi removido. Confira os valores atualizados e, se ainda quiser, remova novamente.'
                        : null)
                    ->warning()
                    ->send();

                $action->cancel();
            });
    }

    /**
     * O Filament descarta em silêncio a ação cujo registro deixou de existir --
     * apagado por outra pessoa com a confirmação aberta, ou com a tabela
     * desatualizada na tela. Nada é removido e não há erro; aqui o usuário passa
     * a ser avisado e a tabela volta para uma página que exista.
     */
    protected function resolveTableAction(array $action, array $parentActions): Action
    {
        try {
            return parent::resolveTableAction($action, $parentActions);
        } catch (ActionNotResolvableException $exception) {
            if ((($action['name'] ?? null) === DeleteAction::getDefaultName()) && filled($action['context']['recordKey'] ?? null)) {
                $this->warnScheduleDateUnavailable();
            }

            throw $exception;
        }
    }

    protected function warnScheduleDateUnavailable(): void
    {
        if ($this->hasWarnedUnavailableScheduleDate) {
            return;
        }

        $this->hasWarnedUnavailableScheduleDate = true;

        Notification::make()
            ->title(PaymentRemovalOutcome::Unavailable->label())
            ->warning()
            ->send();

        $this->keepTablePageWithinRange();
    }

    /**
     * O Filament não recua a paginação sozinho: removida a única linha da
     * última página, a tabela ficaria parada nela exibindo "Nenhum pagamento
     * cadastrado" com registros nas páginas anteriores.
     */
    protected function keepTablePageWithinRange(): void
    {
        $this->flushCachedTableRecords();

        $records = $this->getTableRecords();

        if (($records instanceof LengthAwarePaginator) && $records->isEmpty() && ($records->currentPage() > 1)) {
            $this->setPage($records->lastPage());
        }

        $this->flushCachedTableRecords();
    }

    /**
     * Remover uma data apaga dado financeiro da emissão, então segue a regra dos
     * demais módulos: excluir exige a permissão `.delete` do módulo, que o papel
     * `editor` não tem.
     */
    protected function canRemoveScheduleDates(): bool
    {
        return auth()->user()?->can(AccessPermission::EmissionsDelete->value) ?? false;
    }
}
