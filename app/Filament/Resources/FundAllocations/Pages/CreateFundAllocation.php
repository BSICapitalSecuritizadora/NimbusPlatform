<?php

namespace App\Filament\Resources\FundAllocations\Pages;

use App\Exceptions\Funds\FundAllocationException;
use App\Filament\Resources\FundAllocations\FundAllocationResource;
use App\Filament\Resources\FundAllocations\Schemas\FundAllocationDraftLines;
use App\Filament\Support\DetectsConcurrentUpdates;
use App\Models\BankApplication;
use App\Models\BankApplicationBalanceSnapshot;
use App\Models\FundAllocationPeriod;
use App\Services\Funds\FundAllocationService;
use App\Services\Funds\FundAllocationSuggestionService;
use App\Support\Funds\FundAllocationMoney;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class CreateFundAllocation extends CreateRecord
{
    use DetectsConcurrentUpdates;

    protected static string $resource = FundAllocationResource::class;

    protected static ?string $title = 'Nova alocação';

    protected static ?string $breadcrumb = 'Nova';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-fund-allocation-form-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Selecione a aplicação e a competência, confira o saldo de referência e proponha a distribuição entre os fundos.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('fill_suggestion')
                ->label('Preencher com sugestão')
                ->icon('heroicon-m-sparkles')
                ->color('gray')
                ->action(fn (): mixed => $this->fillFromSuggestion()),
        ];
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->label('Salvar rascunho')
            ->icon('heroicon-m-check');
    }

    protected function getCancelFormAction(): Action
    {
        return parent::getCancelFormAction()
            ->label('Cancelar')
            ->color('gray');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Rascunho salvo com sucesso.';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        $service = app(FundAllocationService::class);

        try {
            $application = BankApplication::query()->findOrFail($data['bank_application_id']);
            $competence = FundAllocationDraftLines::parseCompetenceInput($data['competence'] ?? null);

            if ($competence === null) {
                throw FundAllocationException::invalid('Informe a competência no formato MM/AAAA.');
            }

            $snapshot = BankApplicationBalanceSnapshot::query()
                ->where('bank_application_id', $application->getKey())
                ->whereDate('competence', $competence)
                ->orderByDesc('version')
                ->first();

            if (! $snapshot instanceof BankApplicationBalanceSnapshot) {
                $referenceAmount = FundAllocationDraftLines::normalizeMoneyInput($data['reference_amount'] ?? null);

                if ($referenceAmount === null) {
                    throw FundAllocationException::invalid('Registre o saldo de referência desta competência para criar o rascunho.');
                }

                $snapshot = $service->recordReferenceBalance(
                    $actor,
                    $application,
                    $competence,
                    $referenceAmount,
                    $data['reference_note'] ?? null,
                    isset($data['reference_captured_at']) && $data['reference_captured_at'] !== null && $data['reference_captured_at'] !== ''
                        ? new \DateTimeImmutable((string) $data['reference_captured_at'])
                        : null,
                );
            }

            $lines = [];
            foreach ((array) ($data['lines'] ?? []) as $line) {
                if (! is_array($line) || blank($line['fund_id'] ?? null)) {
                    continue;
                }

                $lines[] = [
                    'fund_id' => (int) $line['fund_id'],
                    'amount' => FundAllocationDraftLines::normalizeMoneyInput($line['amount'] ?? null) ?? '0',
                ];
            }

            $period = FundAllocationPeriod::query()
                ->where('bank_application_id', $application->getKey())
                ->whereDate('competence', $competence)
                ->first();

            if ($period instanceof FundAllocationPeriod && $period->hasEffectiveRevision()) {
                $service->proposeCorrection(
                    $actor,
                    $application,
                    $competence,
                    $snapshot->getKey(),
                    $lines,
                    (string) ($data['correction_reason'] ?? ''),
                );
            } else {
                $service->createDraft(
                    $actor,
                    $application,
                    $competence,
                    $snapshot->getKey(),
                    $lines,
                );
            }

            return FundAllocationPeriod::query()
                ->where('bank_application_id', $application->getKey())
                ->whereDate('competence', $competence)
                ->firstOrFail();
        } catch (FundAllocationException $exception) {
            Notification::make()
                ->danger()
                ->title('Rascunho não salvo.')
                ->body($exception->getMessage())
                ->persistent()
                ->send();

            throw new Halt;
        } catch (Throwable $exception) {
            if (! static::isConcurrentUpdate($exception)) {
                throw $exception;
            }

            report($exception);

            Notification::make()
                ->danger()
                ->title('Rascunho não salvo.')
                ->body('Outra pessoa está atualizando esta alocação. Tente novamente em instantes.')
                ->persistent()
                ->send();

            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string
    {
        return FundAllocationResource::getUrl('view', ['record' => $this->getRecord()]);
    }

    private function fillFromSuggestion(): mixed
    {
        $state = $this->form->getRawState();

        $existing = $state['lines'] ?? [];

        if (is_array($existing) && $existing !== []) {
            Notification::make()
                ->warning()
                ->title('Sugestão não aplicada.')
                ->body('Limpe as linhas atuais para aplicar a sugestão — valores digitados nunca são substituídos automaticamente.')
                ->persistent()
                ->send();

            return null;
        }

        $application = BankApplication::query()->find($state['bank_application_id'] ?? null);
        $competence = FundAllocationDraftLines::parseCompetenceInput($state['competence'] ?? null);

        if (! $application instanceof BankApplication || $competence === null) {
            Notification::make()
                ->warning()
                ->title('Sugestão indisponível.')
                ->body('Selecione a aplicação e a competência antes de pedir a sugestão.')
                ->send();

            return null;
        }

        try {
            $suggestion = app(FundAllocationSuggestionService::class)
                ->suggest(auth()->user(), $application, $competence);
        } catch (FundAllocationException $exception) {
            Notification::make()
                ->danger()
                ->title('Sugestão indisponível.')
                ->body($exception->getMessage())
                ->persistent()
                ->send();

            return null;
        }

        if (! $suggestion->isActionable()) {
            Notification::make()
                ->warning()
                ->title('Sem sugestão para esta competência.')
                ->body($suggestion->reasonMessage())
                ->persistent()
                ->send();

            return null;
        }

        $items = [];
        foreach ($suggestion->lines as $line) {
            $items[] = [
                'fund_id' => (string) $line['fund_id'],
                'amount' => FundAllocationDraftLines::formatMoneyForDisplay(
                    FundAllocationMoney::fromCents($line['suggested_cents'])
                ),
            ];
        }

        $this->form->fill(array_merge($state, ['lines' => $items]));

        $source = FundAllocationDraftLines::competenceLabel($suggestion->sourceCompetence);

        Notification::make()
            ->success()
            ->title('Sugestão aplicada ao rascunho.')
            ->body("Proporções da competência {$source} (revisão #{$suggestion->sourceRevisionId}). Confira cada valor antes de salvar — nada foi aprovado.")
            ->persistent()
            ->send();

        return null;
    }
}
