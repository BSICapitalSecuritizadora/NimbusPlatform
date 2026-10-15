<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\PuCalculator\DTOs\PuObligationRefreshOutcome;
use App\Domain\PuCalculator\Enums\PuObligationRefreshStatus;
use App\Domain\PuCalculator\Services\PuObligationRefreshRecovery;
use App\Enums\AccessPermission;
use App\Models\Emission;
use App\Models\PuObligationRefreshRequest;
use App\Models\PuOperationalIncident;
use App\Models\User;
use App\Support\PuCalculator\PuDisplay;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Retomada autorizada da atualização das obrigações de uma emissão (Fase 8).
 *
 * Só chama {@see PuObligationRefreshRecovery::retry()}, que exige
 * `pu.operations.recover` e motivo e recompõe o que é derivado dos fatos -- não
 * homologa, não liquida e não decide conflito. Quando o pedido parado é de falha
 * PERMANENTE (domínio, governança), a tela exige a confirmação de que a causa foi
 * corrigida: repetir sem corrigir só repete a recusa.
 */
final class PuRefreshRecoveryAction
{
    /**
     * @param  (Closure(): ?int)|null  $emissionId  emissão fora de uma linha de incidente
     */
    public static function make(string $name, ?Closure $emissionId = null): Action
    {
        $resolve = fn (mixed $record = null): ?int => $record instanceof PuOperationalIncident
            ? ($record->emission_id !== null ? (int) $record->emission_id : null)
            : ($emissionId !== null ? $emissionId() : null);

        return Action::make($name)
            ->label('Retomar atualização das obrigações')
            ->icon('heroicon-m-arrow-path')
            ->color('warning')
            ->modalHeading('Retomar atualização das obrigações')
            ->modalDescription('Os pedidos esgotados ou bloqueados desta emissão voltam a pendentes e são executados agora; sem pedido parado, um pedido manual novo é registrado. A atualização só recompõe o esperado e a conciliação a partir dos fatos governados: não homologa curva, não registra nem altera liquidação e não decide conflito.')
            ->modalWidth('3xl')
            ->modalSubmitActionLabel('Retomar')
            ->modalContent(fn (mixed $record = null): Htmlable => view('filament.emissions.pu-refresh-requests', [
                'requests' => self::openRequests($resolve($record)),
            ]))
            ->schema(fn (mixed $record = null): array => [
                Textarea::make('reason')
                    ->label('Motivo da retomada')
                    ->helperText('O que foi verificado ou corrigido. Fica na trilha protegida de auditoria.')
                    ->required()
                    ->rows(3)
                    ->maxLength(2000),
                Checkbox::make('permanent_cause_fixed')
                    ->label('Confirmo que a causa da falha permanente foi corrigida (regra de domínio, governança ou insumo). Sem isso a retomada só repete a recusa.')
                    ->visible(fn (): bool => self::hasPermanentFailure($resolve($record)))
                    ->accepted()
                    ->validationMessages(['accepted' => 'Confirme que a causa permanente foi corrigida antes de retomar.']),
            ])
            ->action(function (array $data, mixed $record = null) use ($resolve): void {
                $emissionId = $resolve($record);

                if ($emissionId === null || ! Emission::query()->whereKey($emissionId)->exists()) {
                    Notification::make()->title('Emissão não encontrada.')->body('O incidente não aponta para uma emissão existente. Nada foi feito.')->warning()->send();

                    return;
                }

                /** @var User $user */
                $user = auth()->user();

                try {
                    $outcome = app(PuObligationRefreshRecovery::class)->retry($emissionId, $user, (string) ($data['reason'] ?? ''));
                } catch (AuthorizationException|InvalidArgumentException $exception) {
                    Notification::make()->title('Retomada não executada.')->body($exception->getMessage().' Nada foi alterado.')->danger()->send();

                    return;
                }

                self::notify($outcome);
            })
            ->visible(fn (): bool => auth()->user()?->can(AccessPermission::PuOperationsRecover->value) ?? false);
    }

    /**
     * @return Collection<int, PuObligationRefreshRequest>
     */
    public static function openRequests(?int $emissionId): Collection
    {
        if ($emissionId === null) {
            return collect();
        }

        return PuObligationRefreshRequest::query()
            ->where('emission_id', $emissionId)
            ->open()
            ->orderBy('id')
            ->limit(50)
            ->get();
    }

    private static function hasPermanentFailure(?int $emissionId): bool
    {
        return self::openRequests($emissionId)->contains(fn (PuObligationRefreshRequest $request): bool => $request->status === PuObligationRefreshStatus::Blocked
            || ($request->last_error_category !== null && ! $request->last_error_category->isRetryable()));
    }

    private static function notify(PuObligationRefreshOutcome $outcome): void
    {
        match (true) {
            $outcome->succeeded() => Notification::make()
                ->title('Obrigações atualizadas.')
                ->body('A atualização terminou com sucesso. O esperado e a conciliação foram recompostos a partir dos fatos governados.')
                ->success()
                ->send(),
            $outcome->status === PuObligationRefreshOutcome::NOTHING_TO_DO => Notification::make()
                ->title('Nada a executar agora.')
                ->body('Outro processo já está atualizando esta emissão, ou não havia pedido elegível. Confira os pedidos abertos em alguns minutos.')
                ->info()
                ->send(),
            default => Notification::make()
                ->title('A atualização falhou de novo.')
                ->body(sprintf(
                    '%s%s Situação do pedido: %s.',
                    $outcome->failureCategory !== null ? $outcome->failureCategory->label().'. ' : '',
                    (string) $outcome->message,
                    match ($outcome->status) {
                        PuObligationRefreshOutcome::RETRY_SCHEDULED => 'nova tentativa agendada',
                        PuObligationRefreshOutcome::EXHAUSTED => 'tentativas esgotadas',
                        PuObligationRefreshOutcome::BLOCKED => 'bloqueado por falha permanente — corrija a causa antes de retomar',
                        default => $outcome->status,
                    },
                ))
                ->danger()
                ->persistent()
                ->send(),
        };
    }

    /**
     * Rótulo curto de um pedido para listas.
     */
    public static function describe(PuObligationRefreshRequest $request): string
    {
        return sprintf(
            '#%d %s · %d tentativa(s)%s',
            $request->id,
            $request->status->label(),
            (int) $request->attempts,
            $request->next_attempt_at !== null ? ' · próxima em '.PuDisplay::dateTime($request->next_attempt_at) : '',
        );
    }
}
