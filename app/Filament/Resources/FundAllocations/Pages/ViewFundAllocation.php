<?php

namespace App\Filament\Resources\FundAllocations\Pages;

use App\DTOs\Funds\FundAllocationSuggestion;
use App\Exceptions\Funds\FundAllocationException;
use App\Filament\Resources\FundAllocations\FundAllocationResource;
use App\Filament\Resources\FundAllocations\Schemas\FundAllocationDraftLines;
use App\Filament\Support\DetectsConcurrentUpdates;
use App\Models\BankApplicationBalanceSnapshot;
use App\Models\Fund;
use App\Models\FundAllocationPeriod;
use App\Models\FundAllocationRevision;
use App\Services\Funds\FundAllocationService;
use App\Services\Funds\FundAllocationSuggestionService;
use App\Support\Funds\FundAllocationMoney;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Throwable;

class ViewFundAllocation extends ViewRecord
{
    use DetectsConcurrentUpdates;

    protected static string $resource = FundAllocationResource::class;

    protected static ?string $title = 'Alocação por competência';

    protected static ?string $breadcrumb = 'Detalhe';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-fund-allocation-view-page',
    ];

    public function getSubheading(): ?string
    {
        $record = $this->getRecord();

        if (! $record instanceof FundAllocationPeriod) {
            return null;
        }

        return FundAllocationResource::getRecordTitle($record);
    }

    /**
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->recordReferenceAction(),
            $this->suggestAction(),
            $this->proposeAction(),
            $this->editDraftAction(),
            $this->submitAction(),
            $this->approveAction(),
            $this->discardAction(),
        ];
    }

    private function recordReferenceAction(): Action
    {
        return Action::make('record_reference')
            ->label('Registrar saldo de referência')
            ->icon('heroicon-m-banknotes')
            ->color('gray')
            ->visible(fn (): bool => auth()->user()?->can(FundAllocationService::PERMISSION_MANAGE_REFERENCES) ?? false)
            ->modalHeading('Registrar saldo de referência manual')
            ->modalDescription('Um novo registro cria uma nova versão sem reescrever aprovações vinculadas à anterior. Para alocar sobre a nova versão, proponha uma correção.')
            ->modalSubmitActionLabel('Registrar versão')
            ->form(fn (FundAllocationPeriod $record): array => [
                FundAllocationDraftLines::moneyInput('reference_amount', 'Valor de referência')
                    ->required(),
                TextInput::make('reference_captured_at')
                    ->label('Data de referência')
                    ->type('date')
                    ->default(fn (): string => now()->toDateString())
                    ->required(),
                Textarea::make('reference_note')
                    ->label('Observação')
                    ->rows(2)
                    ->maxLength(2000)
                    ->columnSpanFull(),
            ])
            ->action(function (FundAllocationPeriod $record, array $data): void {
                $this->runDomainAction(
                    successTitle: 'Saldo de referência registrado.',
                    call: function () use ($record, $data): string {
                        $amount = FundAllocationDraftLines::normalizeMoneyInput($data['reference_amount'] ?? null);

                        if ($amount === null) {
                            throw FundAllocationException::invalid('Informe o valor do saldo de referência.');
                        }

                        $snapshot = app(FundAllocationService::class)->recordReferenceBalance(
                            auth()->user(),
                            $record->bankApplication()->firstOrFail(),
                            $record->competence->toDateString(),
                            $amount,
                            $data['reference_note'] ?? null,
                            isset($data['reference_captured_at']) && $data['reference_captured_at'] !== ''
                                ? new \DateTimeImmutable((string) $data['reference_captured_at'])
                                : null,
                        );

                        return "Versão v{$snapshot->version} registrada para a competência.";
                    }
                );
            });
    }

    private function suggestAction(): Action
    {
        return Action::make('suggest')
            ->label('Sugerir distribuição')
            ->icon('heroicon-m-sparkles')
            ->color('gray')
            ->visible(fn (FundAllocationPeriod $record): bool => ! $record->hasOpenRevision() && $this->canPropose($record))
            ->modalHeading('Sugestão pela competência anterior aprovada')
            ->modalDescription('A sugestão repete as proporções da última competência anterior aprovada — parcela não alocada preservada — e gera um rascunho. Nada é aprovado automaticamente.')
            ->modalSubmitActionLabel('Criar rascunho a partir da sugestão')
            ->form(fn (FundAllocationPeriod $record): array => [
                Placeholder::make('suggestion_breakdown')
                    ->hiddenLabel()
                    ->content(fn (): HtmlString => $this->suggestionBreakdownHtml($record)),
                Textarea::make('correction_reason')
                    ->label('Motivo da correção')
                    ->required()
                    ->rows(2)
                    ->maxLength(2000)
                    ->visible(fn (): bool => $record->hasEffectiveRevision())
                    ->validationMessages(['required' => 'Correções exigem um motivo.']),
            ])
            ->action(function (FundAllocationPeriod $record, array $data): void {
                $this->runDomainAction(
                    successTitle: 'Rascunho criado a partir da sugestão.',
                    call: function () use ($record, $data): ?string {
                        $suggestion = $this->computeSuggestion($record);

                        if (! $suggestion->isActionable()) {
                            throw FundAllocationException::invalid(
                                $suggestion->reasonMessage() ?? 'Sugestão indisponível.'
                            );
                        }

                        $service = app(FundAllocationService::class);
                        $actor = auth()->user();
                        $application = $record->bankApplication()->firstOrFail();
                        $competence = $record->competence->toDateString();

                        if ($record->hasEffectiveRevision()) {
                            $service->proposeCorrection(
                                $actor,
                                $application,
                                $competence,
                                $suggestion->snapshotId,
                                $suggestion->toDraftLines(),
                                (string) ($data['correction_reason'] ?? ''),
                            );
                        } else {
                            $service->createDraft(
                                $actor,
                                $application,
                                $competence,
                                $suggestion->snapshotId,
                                $suggestion->toDraftLines(),
                            );
                        }

                        $source = FundAllocationDraftLines::competenceLabel($suggestion->sourceCompetence);

                        return "Proporções da competência {$source} (revisão #{$suggestion->sourceRevisionId}). Confira e envie para análise.";
                    }
                );
            });
    }

    private function proposeAction(): Action
    {
        return Action::make('propose')
            ->label(fn (FundAllocationPeriod $record): string => $record->hasEffectiveRevision() ? 'Propor correção' : 'Nova proposta')
            ->icon('heroicon-m-plus')
            ->color('primary')
            ->visible(fn (FundAllocationPeriod $record): bool => ! $record->hasOpenRevision() && $this->canPropose($record))
            ->modalHeading(fn (FundAllocationPeriod $record): string => $record->hasEffectiveRevision() ? 'Propor correção governada' : 'Nova proposta de distribuição')
            ->modalDescription('Os totais são recalculados e validados pelo servidor na gravação.')
            ->modalSubmitActionLabel('Salvar rascunho')
            ->form(fn (FundAllocationPeriod $record): array => [
                FundAllocationDraftLines::linesRepeater(fixedApplicationId: (int) $record->bank_application_id),
                Textarea::make('correction_reason')
                    ->label('Motivo da correção')
                    ->required()
                    ->rows(2)
                    ->maxLength(2000)
                    ->visible(fn (): bool => $record->hasEffectiveRevision())
                    ->validationMessages(['required' => 'Correções exigem um motivo.']),
            ])
            ->action(function (FundAllocationPeriod $record, array $data): void {
                $this->runDomainAction(
                    successTitle: 'Rascunho salvo com sucesso.',
                    call: function () use ($record, $data): ?string {
                        $service = app(FundAllocationService::class);
                        $actor = auth()->user();
                        $application = $record->bankApplication()->firstOrFail();
                        $competence = $record->competence->toDateString();
                        $snapshotId = $this->currentSnapshotId($record);

                        if ($record->hasEffectiveRevision()) {
                            $service->proposeCorrection(
                                $actor,
                                $application,
                                $competence,
                                $snapshotId,
                                $this->normalizeLines($data['lines'] ?? []),
                                (string) ($data['correction_reason'] ?? ''),
                            );

                            return null;
                        }

                        $service->createDraft(
                            $actor,
                            $application,
                            $competence,
                            $snapshotId,
                            $this->normalizeLines($data['lines'] ?? []),
                        );

                        return null;
                    }
                );
            });
    }

    private function editDraftAction(): Action
    {
        return Action::make('edit_draft')
            ->label('Editar rascunho')
            ->icon('heroicon-m-pencil-square')
            ->color('gray')
            ->visible(fn (FundAllocationPeriod $record): bool => $record->openRevision instanceof FundAllocationRevision
                && $record->openRevision->isDraft()
                && (auth()->user()?->can($this->draftPermission($record->openRevision)) ?? false))
            ->modalHeading('Editar rascunho')
            ->modalDescription('Somente rascunhos aceitam edição. Revisões em análise permanecem congeladas.')
            ->modalSubmitActionLabel('Salvar rascunho')
            ->fillForm(fn (FundAllocationPeriod $record): array => [
                'lines' => $this->revisionLineItems($record->openRevision),
            ])
            ->form(fn (FundAllocationPeriod $record): array => [
                FundAllocationDraftLines::linesRepeater(fixedApplicationId: (int) $record->bank_application_id),
            ])
            ->action(function (FundAllocationPeriod $record, array $data): void {
                $this->runDomainAction(
                    successTitle: 'Rascunho atualizado.',
                    call: function () use ($record, $data): ?string {
                        app(FundAllocationService::class)->updateDraft(
                            auth()->user(),
                            $record->openRevision()->firstOrFail(),
                            $this->normalizeLines($data['lines'] ?? []),
                        );

                        return null;
                    }
                );
            });
    }

    private function submitAction(): Action
    {
        return Action::make('submit')
            ->label('Enviar para análise')
            ->icon('heroicon-m-paper-airplane')
            ->color('primary')
            ->visible(fn (FundAllocationPeriod $record): bool => $record->openRevision instanceof FundAllocationRevision
                && $record->openRevision->isDraft()
                && (auth()->user()?->can(FundAllocationService::PERMISSION_SUBMIT) ?? false))
            ->requiresConfirmation()
            ->modalHeading('Enviar para análise?')
            ->modalDescription('O rascunho será congelado para análise. Quem envia não pode aprovar a própria proposta.')
            ->modalSubmitActionLabel('Enviar')
            ->action(function (FundAllocationPeriod $record): void {
                $this->runDomainAction(
                    successTitle: 'Revisão enviada para análise.',
                    call: function () use ($record): ?string {
                        app(FundAllocationService::class)->submitForReview(
                            auth()->user(),
                            $record->openRevision()->firstOrFail(),
                        );

                        return null;
                    }
                );
            });
    }

    private function approveAction(): Action
    {
        return Action::make('approve')
            ->label('Aprovar distribuição')
            ->icon('heroicon-m-check-badge')
            ->color('success')
            ->visible(fn (FundAllocationPeriod $record): bool => $this->canApprove($record))
            ->requiresConfirmation()
            ->modalHeading('Aprovar esta distribuição?')
            ->modalDescription(fn (FundAllocationPeriod $record): string => $this->approvalSummary($record))
            ->modalSubmitActionLabel('Aprovar')
            ->action(function (FundAllocationPeriod $record): void {
                $this->runDomainAction(
                    successTitle: 'Distribuição aprovada.',
                    call: function () use ($record): ?string {
                        // Fresh key per explicit confirmation: retries replay
                        // safely in the service, and a double click can never
                        // mint a second effective revision.
                        app(FundAllocationService::class)->approve(
                            auth()->user(),
                            $record->openRevision()->firstOrFail(),
                            Str::uuid()->toString(),
                        );

                        return 'A distribuição passa a vigorar para a competência.';
                    }
                );
            });
    }

    private function discardAction(): Action
    {
        return Action::make('discard')
            ->label('Descartar revisão')
            ->icon('heroicon-m-trash')
            ->color('danger')
            ->visible(fn (FundAllocationPeriod $record): bool => $record->openRevision instanceof FundAllocationRevision
                && (auth()->user()?->can($this->draftPermission($record->openRevision)) ?? false))
            ->modalHeading('Descartar a revisão em aberto?')
            ->modalDescription('A revisão descartada permanece como histórico inerte; o período aceita uma nova proposta em seguida.')
            ->modalSubmitActionLabel('Descartar')
            ->form([
                Textarea::make('reason')
                    ->label('Motivo (opcional)')
                    ->rows(2)
                    ->maxLength(2000),
            ])
            ->action(function (FundAllocationPeriod $record, array $data): void {
                $this->runDomainAction(
                    successTitle: 'Revisão descartada.',
                    call: function () use ($record, $data): ?string {
                        app(FundAllocationService::class)->discardDraft(
                            auth()->user(),
                            $record->openRevision()->firstOrFail(),
                            $data['reason'] ?? null,
                        );

                        return null;
                    }
                );
            });
    }

    /**
     * Every workflow action funnels through the domain service — never direct
     * model writes — and every failure surfaces as a failure, never as a
     * silent success.
     */
    private function runDomainAction(string $successTitle, Closure $call): void
    {
        try {
            $body = $call();
        } catch (FundAllocationException $exception) {
            $this->refreshRecordState();

            Notification::make()
                ->danger()
                ->title('Operação recusada.')
                ->body($exception->getMessage())
                ->persistent()
                ->send();

            throw new Halt;
        } catch (Throwable $exception) {
            if (! static::isConcurrentUpdate($exception)) {
                throw $exception;
            }

            report($exception);
            $this->refreshRecordState();

            Notification::make()
                ->danger()
                ->title('Operação não concluída.')
                ->body('Outra pessoa está atualizando esta alocação. Tente novamente em instantes.')
                ->persistent()
                ->send();

            throw new Halt;
        }

        $this->refreshRecordState();

        Notification::make()
            ->success()
            ->title($successTitle)
            ->body($body)
            ->send();
    }

    private function refreshRecordState(): void
    {
        $record = $this->getRecord();

        if ($record instanceof FundAllocationPeriod) {
            $record->refresh();
            $record->load(['openRevision.snapshot', 'effectiveRevision.snapshot', 'bankApplication']);
        }
    }

    private function canPropose(FundAllocationPeriod $record): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        if ($record->hasEffectiveRevision()) {
            return $user->can(FundAllocationService::PERMISSION_CORRECT);
        }

        return $user->can(FundAllocationService::PERMISSION_CREATE);
    }

    private function canApprove(FundAllocationPeriod $record): bool
    {
        $open = $record->openRevision;

        if (! $open instanceof FundAllocationRevision || ! $open->isPendingReview()) {
            return false;
        }

        $user = auth()->user();

        if ($user === null || ! $user->can(FundAllocationService::PERMISSION_APPROVE)) {
            return false;
        }

        // UI mirror only: the service re-enforces segregation of duties.
        if ((int) $user->getKey() === (int) $open->proposed_by_user_id) {
            return false;
        }

        return $open->submitted_by_user_id === null
            || (int) $user->getKey() !== (int) $open->submitted_by_user_id;
    }

    private function draftPermission(FundAllocationRevision $revision): string
    {
        return $revision->isCorrection()
            ? FundAllocationService::PERMISSION_CORRECT
            : FundAllocationService::PERMISSION_CREATE;
    }

    private function currentSnapshotId(FundAllocationPeriod $record): int
    {
        $snapshot = BankApplicationBalanceSnapshot::query()
            ->where('bank_application_id', $record->bank_application_id)
            ->whereDate('competence', $record->competence->toDateString())
            ->orderByDesc('version')
            ->first();

        if (! $snapshot instanceof BankApplicationBalanceSnapshot) {
            throw FundAllocationException::invalid('Registre o saldo de referência desta competência antes de propor a distribuição.');
        }

        return $snapshot->getKey();
    }

    /**
     * @return list<array{fund_id: int, amount: string}>
     */
    private function normalizeLines(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $lines = [];

        foreach ($items as $item) {
            if (! is_array($item) || blank($item['fund_id'] ?? null)) {
                continue;
            }

            $lines[] = [
                'fund_id' => (int) $item['fund_id'],
                'amount' => FundAllocationDraftLines::normalizeMoneyInput($item['amount'] ?? null) ?? '0',
            ];
        }

        return $lines;
    }

    /**
     * @return list<array{fund_id: string, amount: string|null}>
     */
    private function revisionLineItems(?FundAllocationRevision $revision): array
    {
        if (! $revision instanceof FundAllocationRevision) {
            return [];
        }

        return $revision->lines()->orderBy('fund_id')->get()
            ->map(fn ($line): array => [
                'fund_id' => (string) $line->fund_id,
                'amount' => FundAllocationDraftLines::formatMoneyForDisplay((string) $line->amount),
            ])
            ->all();
    }

    private function approvalSummary(FundAllocationPeriod $record): string
    {
        $open = $record->openRevision;

        if (! $open instanceof FundAllocationRevision) {
            return 'A distribuição completa será aprovada atomicamente para a competência.';
        }

        $total = $this->safeFormat($open->total_amount);
        $unallocated = $this->safeFormat($open->unallocated_amount);
        $version = $open->snapshot !== null ? "v{$open->snapshot->version}" : '—';

        return "Total {$total} · não alocado {$unallocated} · referência {$version}. A distribuição completa é aprovada atomicamente e passa a vigorar imediatamente.";
    }

    private function computeSuggestion(FundAllocationPeriod $record): FundAllocationSuggestion
    {
        return app(FundAllocationSuggestionService::class)->suggest(
            auth()->user(),
            $record->bankApplication()->firstOrFail(),
            $record->competence->toDateString(),
        );
    }

    private function suggestionBreakdownHtml(FundAllocationPeriod $record): HtmlString
    {
        try {
            $suggestion = $this->computeSuggestion($record);
        } catch (FundAllocationException $exception) {
            return new HtmlString('<p class="text-sm">'.e($exception->getMessage()).'</p>');
        }

        if (! $suggestion->isActionable()) {
            return new HtmlString('<p class="text-sm">'.e($suggestion->reasonMessage() ?? 'Sugestão indisponível.').'</p>');
        }

        $funds = Fund::query()
            ->whereIn('id', collect($suggestion->lines)->pluck('fund_id')
                ->merge(collect($suggestion->missingFunds)->pluck('fund_id'))
                ->unique()->values()->all())
            ->with(['fundName', 'emission'])
            ->get()
            ->keyBy('id');

        $showEmissions = auth()->user()?->can('emissions.view') ?? false;

        $rows = '';
        foreach ($suggestion->lines as $line) {
            $fund = $funds->get($line['fund_id']);
            $name = e($fund?->fundName?->name ?? "Fundo #{$line['fund_id']}");
            $extra = $line['is_new'] ? ' <span class="fi-badge fi-color-info">novo · revisar</span>' : '';
            $emission = $showEmissions ? ' — '.e($fund?->emission?->name ?? '—') : '';
            $amount = FundAllocationDraftLines::formatCents($line['suggested_cents']);
            $rows .= "<li>{$name} · #{$line['fund_id']}{$emission}: <strong class=\"font-mono\">{$amount}</strong>{$extra}</li>";
        }

        foreach ($suggestion->missingFunds as $missing) {
            $fund = $funds->get($missing['fund_id']);
            $name = e($fund?->fundName?->name ?? "Fundo #{$missing['fund_id']}");
            $prior = FundAllocationDraftLines::formatCents($missing['prior_cents']);
            $rows .= "<li>{$name} · #{$missing['fund_id']}: <span class=\"fi-badge fi-color-warning\">fora da aplicação</span> (tinha {$prior} — permanece não alocado)</li>";
        }

        $source = FundAllocationDraftLines::competenceLabel($suggestion->sourceCompetence);
        $total = FundAllocationDraftLines::formatCents($suggestion->totalCents);
        $unallocated = FundAllocationDraftLines::formatCents($suggestion->unallocatedCents);

        return new HtmlString(
            "<p class=\"text-sm\">Proporções da competência {$source} (revisão #{$suggestion->sourceRevisionId}):</p>"
            ."<ul class=\"list-disc ps-5 text-sm\">{$rows}</ul>"
            ."<p class=\"text-sm\">Total sugerido: <strong class=\"font-mono\">{$total}</strong> · não alocado: <strong class=\"font-mono\">{$unallocated}</strong></p>"
        );
    }

    private function safeFormat(mixed $value): string
    {
        try {
            return FundAllocationDraftLines::formatCents(FundAllocationMoney::toCents((string) ($value ?? '0')));
        } catch (\InvalidArgumentException) {
            return '—';
        }
    }
}
