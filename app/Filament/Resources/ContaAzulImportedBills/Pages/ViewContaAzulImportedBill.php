<?php

namespace App\Filament\Resources\ContaAzulImportedBills\Pages;

use App\Exceptions\Funds\ContaAzulReconciliationException;
use App\Filament\Resources\ContaAzulImportedBills\ContaAzulImportedBillResource;
use App\Models\BankAccount;
use App\Models\ContaAzulImportedBill;
use App\Models\Emission;
use App\Models\ExpenseHistory;
use App\Services\Funds\ContaAzulBillAttributionService;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;

/**
 * Staged-bill review. Every action here delegates to
 * ContaAzulBillAttributionService: the page collects the decision inputs,
 * the service authorizes, locks, records and audits.
 */
class ViewContaAzulImportedBill extends ViewRecord
{
    protected static string $resource = ContaAzulImportedBillResource::class;

    protected static ?string $breadcrumb = 'Revisar';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page',
    ];

    public function getTitle(): string
    {
        /** @var ContaAzulImportedBill $record */
        $record = $this->getRecord();

        return 'Conciliar '.$record->maskedBillId();
    }

    /**
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->markReadyAction(),
            $this->markInsufficientAction(),
            $this->attributeAction(),
            $this->correctAction(),
        ];
    }

    private function markReadyAction(): Action
    {
        return Action::make('mark_ready')
            ->label('Marcar pronto para revisão')
            ->icon('heroicon-o-check-circle')
            ->color('gray')
            ->visible(fn (ContaAzulImportedBill $record): bool => $this->canReview($record)
                && $record->reconciliation_status !== ContaAzulImportedBill::RECONCILIATION_READY_FOR_REVIEW)
            ->form([
                Textarea::make('note')
                    ->label('Nota de revisão (opcional)')
                    ->rows(3)
                    ->maxLength(2000),
            ])
            ->action(function (ContaAzulImportedBill $record, array $data): void {
                $this->runGoverned(fn (): ContaAzulImportedBill => app(ContaAzulBillAttributionService::class)->markReady(
                    auth()->user(),
                    $record,
                    $data['note'] ?? null,
                ), 'Título marcado como pronto para revisão.');
            });
    }

    private function markInsufficientAction(): Action
    {
        return Action::make('mark_insufficient')
            ->label('Informação insuficiente')
            ->icon('heroicon-o-exclamation-circle')
            ->color('warning')
            ->visible(fn (ContaAzulImportedBill $record): bool => $this->canReview($record)
                && $record->reconciliation_status !== ContaAzulImportedBill::RECONCILIATION_INSUFFICIENT_INFORMATION)
            ->form([
                Textarea::make('note')
                    ->label('O que está faltando?')
                    ->required()
                    ->minLength(ContaAzulBillAttributionService::MIN_REASON_LENGTH)
                    ->rows(3)
                    ->maxLength(2000),
            ])
            ->action(function (ContaAzulImportedBill $record, array $data): void {
                $this->runGoverned(fn (): ContaAzulImportedBill => app(ContaAzulBillAttributionService::class)->markInsufficient(
                    auth()->user(),
                    $record,
                    $data['note'],
                ), 'Título sinalizado como informação insuficiente.');
            });
    }

    private function attributeAction(): Action
    {
        return Action::make('attribute')
            ->label('Atribuir a uma emissão')
            ->icon('heroicon-o-link')
            ->color('primary')
            ->visible(fn (ContaAzulImportedBill $record): bool => ! $record->isAttributed()
                && (auth()->user()?->can(ContaAzulBillAttributionService::PERMISSION_ATTRIBUTE) ?? false))
            ->disabled(fn (ContaAzulImportedBill $record): bool => $record->import_status === ContaAzulImportedBill::IMPORT_ERROR
                || $record->reconciliation_status === ContaAzulImportedBill::RECONCILIATION_INSUFFICIENT_INFORMATION)
            ->tooltip(fn (ContaAzulImportedBill $record): ?string => match (true) {
                $record->import_status === ContaAzulImportedBill::IMPORT_ERROR => 'Título com erro de importação: sinalize informação insuficiente.',
                $record->reconciliation_status === ContaAzulImportedBill::RECONCILIATION_INSUFFICIENT_INFORMATION => 'Resolva a pendência e marque como pronto antes de atribuir.',
                default => null,
            })
            ->modalHeading('Atribuir título a uma emissão')
            ->modalDescription(fn (ContaAzulImportedBill $record): string => $this->decisionWarnings($record))
            ->form(fn (ContaAzulImportedBill $record): array => [
                Select::make('emission_id')
                    ->label('Emissão')
                    ->required()
                    ->searchable()
                    ->reactive()
                    ->options(fn (): array => $this->candidateOptions($record))
                    ->getSearchResultsUsing(fn (string $search): array => $this->searchViewableEmissions($search))
                    ->getOptionLabelUsing(fn (?string $value): ?string => $value !== null
                        ? Emission::query()->whereKey($value)->value('name')
                        : null)
                    ->helperText('Emissões vinculadas à conta aparecem como contexto. Nada é inferido: a escolha é sempre uma decisão explícita.'),

                Textarea::make('reason')
                    ->label('Justificativa')
                    ->required()
                    ->minLength(ContaAzulBillAttributionService::MIN_REASON_LENGTH)
                    ->rows(3)
                    ->maxLength(2000),

                Checkbox::make('exceptional_confirmed')
                    ->label('Confirmo a atribuição excepcional')
                    ->helperText('A emissão escolhida não está vinculada à conta deste título. A confirmação e a justificativa detalhada são obrigatórias.')
                    ->visible(fn (Get $get) => $this->isExceptionalChoice($record, $get('emission_id'))),

                Checkbox::make('allow_incomplete')
                    ->label('Atribuir sem importação completa (exceção)')
                    ->helperText('O conteúdo deste título ainda não foi verificado por uma importação completa. A exceção exige motivo próprio.')
                    ->visible(fn (): bool => ! $record->hasVerifiedContent()),

                Textarea::make('incomplete_exception_reason')
                    ->label('Motivo da exceção de incompletude')
                    ->required()
                    ->minLength(ContaAzulBillAttributionService::MIN_REASON_LENGTH)
                    ->rows(2)
                    ->maxLength(2000)
                    ->visible(fn (Get $get): bool => (bool) $get('allow_incomplete')),

                Placeholder::make('source_version')
                    ->label('Versão da fonte em revisão')
                    ->content(fn (): string => substr($record->payload_fingerprint, 0, 16).'…'),

                Hidden::make('expected_fingerprint')->default($record->payload_fingerprint),
            ])
            ->action(function (ContaAzulImportedBill $record, array $data): void {
                $emission = Emission::query()->whereKey($data['emission_id'])->firstOrFail();

                $this->runGoverned(function () use ($record, $emission, $data): void {
                    app(ContaAzulBillAttributionService::class)->attribute(
                        auth()->user(),
                        $record,
                        $emission,
                        $data['reason'],
                        $data['expected_fingerprint'],
                        [
                            'allow_incomplete' => (bool) ($data['allow_incomplete'] ?? false),
                            'incomplete_exception_reason' => $data['incomplete_exception_reason'] ?? null,
                            'exceptional_confirmed' => (bool) ($data['exceptional_confirmed'] ?? false),
                        ],
                    );
                }, "Título atribuído à emissão {$emission->name}.");
            });
    }

    private function correctAction(): Action
    {
        return Action::make('correct')
            ->label('Corrigir atribuição')
            ->icon('heroicon-o-pencil-square')
            ->color('warning')
            ->visible(fn (ContaAzulImportedBill $record): bool => $record->isAttributed()
                && (auth()->user()?->can(ContaAzulBillAttributionService::PERMISSION_CORRECT) ?? false))
            ->modalHeading('Corrigir atribuição')
            ->modalDescription(fn (ContaAzulImportedBill $record): string => 'A decisão anterior é preservada no histórico. '.$this->decisionWarnings($record))
            ->form(fn (ContaAzulImportedBill $record): array => [
                Select::make('emission_id')
                    ->label('Emissão')
                    ->required()
                    ->searchable()
                    ->reactive()
                    ->default($record->attributed_emission_id)
                    ->options(fn (): array => $this->candidateOptions($record))
                    ->getSearchResultsUsing(fn (string $search): array => $this->searchViewableEmissions($search))
                    ->getOptionLabelUsing(fn (?string $value): ?string => $value !== null
                        ? Emission::query()->whereKey($value)->value('name')
                        : null),

                Textarea::make('reason')
                    ->label('Motivo da correção')
                    ->required()
                    ->minLength(ContaAzulBillAttributionService::MIN_REASON_LENGTH)
                    ->rows(3)
                    ->maxLength(2000),

                Checkbox::make('exceptional_confirmed')
                    ->label('Confirmo a atribuição excepcional')
                    ->visible(fn (Get $get) => $this->isExceptionalChoice($record, $get('emission_id'))),

                Placeholder::make('source_version')
                    ->label('Versão da fonte em revisão')
                    ->content(fn (): string => substr($record->payload_fingerprint, 0, 16).'…'),

                Hidden::make('expected_fingerprint')->default($record->payload_fingerprint),
                Hidden::make('expected_attribution_id')->default($record->latestAttribution()?->getKey()),
            ])
            ->action(function (ContaAzulImportedBill $record, array $data): void {
                $emission = Emission::query()->whereKey($data['emission_id'])->firstOrFail();

                $this->runGoverned(function () use ($record, $emission, $data): void {
                    app(ContaAzulBillAttributionService::class)->correct(
                        auth()->user(),
                        $record,
                        $emission,
                        $data['reason'],
                        $data['expected_fingerprint'],
                        (int) $data['expected_attribution_id'],
                        ['exceptional_confirmed' => (bool) ($data['exceptional_confirmed'] ?? false)],
                    );
                }, "Atribuição corrigida para {$emission->name}. O histórico preserva a decisão anterior.");
            });
    }

    private function canReview(ContaAzulImportedBill $record): bool
    {
        return ! $record->isAttributed()
            && (auth()->user()?->can(ContaAzulBillAttributionService::PERMISSION_ATTRIBUTE) ?? false);
    }

    /**
     * @param  callable(): mixed  $operation
     */
    private function runGoverned(callable $operation, string $success): void
    {
        try {
            $operation();

            Notification::make()->title($success)->success()->send();
        } catch (ContaAzulReconciliationException $exception) {
            Notification::make()->title('Decisão não registrada.')->body($exception->getMessage())->danger()->persistent()->send();
        }
    }

    /**
     * @return array<int, string>
     */
    private function candidateOptions(ContaAzulImportedBill $record): array
    {
        return app(ContaAzulBillAttributionService::class)
            ->candidateEmissions(auth()->user(), $record)
            ->mapWithKeys(fn (Emission $emission): array => [$emission->getKey() => $emission->name])
            ->all();
    }

    /**
     * Search across viewable emissions only: names the actor may not see
     * never appear as options.
     *
     * @return array<int, string>
     */
    private function searchViewableEmissions(string $search): array
    {
        if (! (auth()->user()?->can('emissions.view') ?? false)) {
            return [];
        }

        return Emission::query()
            ->where('name', 'like', '%'.$search.'%')
            ->orderBy('name')
            ->limit(25)
            ->pluck('name', 'id')
            ->all();
    }

    private function isExceptionalChoice(ContaAzulImportedBill $record, mixed $emissionId): bool
    {
        if (! is_numeric($emissionId)) {
            return false;
        }

        $account = $record->bankAccount()->first();

        if (! $account instanceof BankAccount) {
            return true;
        }

        return ! (clone $account->linkedFundQuery())->where('emission_id', (int) $emissionId)->exists();
    }

    private function decisionWarnings(ContaAzulImportedBill $record): string
    {
        $warnings = [];

        if (! $record->hasVerifiedContent()) {
            $warnings[] = 'Conteúdo ainda não verificado por uma importação completa.';
        }

        if ($record->findLegacyHistory() instanceof ExpenseHistory) {
            $warnings[] = 'Título já registrado no fluxo legado: esta decisão não cria outra despesa.';
        }

        if ($record->needs_re_review) {
            $warnings[] = 'A fonte mudou após a decisão vigente: '.($record->re_review_reason ?? 'revisão pendente.');
        }

        if ($warnings === []) {
            return 'Confira a evidência antes de decidir. A decisão é registrada com responsável, data e justificativa.';
        }

        return implode(' ', $warnings);
    }
}
