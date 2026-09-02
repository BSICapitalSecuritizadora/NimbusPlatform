<?php

namespace App\Services;

use App\Enums\MeasurementResponsibility;
use App\Enums\OperationStatus;
use App\Exceptions\OperationLifecycleException;
use App\Models\Measurement;
use App\Models\Operation;
use App\Models\ResponsibilityDelegation;
use App\Models\User;
use App\Support\Operations\OperationReopenPreflight;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A única porta de mudança de situação de uma operação.
 *
 * O desenho é deliberado: em vez de espalhar "e se a operação estiver
 * encerrada?" por cada caminho de escrita do domínio, o encerramento é o portão.
 * Uma operação só vira terminal quando não há mais nada aberto nela, e por isso
 * o estado "operação encerrada com medição em andamento" não é apenas proibido
 * -- é inalcançável. Todo o resto do sistema pode continuar sem saber que o
 * lifecycle existe.
 *
 * A ordem de locks é a mesma já estabelecida no módulo de medições: Operation,
 * depois Measurements por id. Nada aqui inverte essa ordem, porque a criação de
 * medição e a aprovação da Engenharia disputam exatamente estas mesmas linhas.
 */
class OperationLifecycleService
{
    private const MAX_REASON_LENGTH = 1000;

    public function __construct(
        private readonly ResponsibilityDelegationService $delegations,
        private readonly MeasurementAuthorizationService $authorization,
    ) {}

    public function activate(Operation $operation, User $actor): Operation
    {
        return $this->transitionTo($operation, OperationStatus::Active, $actor);
    }

    public function complete(Operation $operation, User $actor): Operation
    {
        return $this->transitionTo($operation, OperationStatus::Completed, $actor);
    }

    public function cancel(Operation $operation, User $actor, string $reason): Operation
    {
        return $this->transitionTo($operation, OperationStatus::Canceled, $actor, $reason);
    }

    public function reopen(Operation $operation, User $actor, string $reason): Operation
    {
        return $this->transitionTo($operation, OperationStatus::Active, $actor, $reason);
    }

    /**
     * Transição serializada pela própria operação.
     *
     * O status é relido de dentro do lock, nunca do modelo que chegou por
     * parâmetro: entre a renderização do botão e a confirmação do modal, outra
     * requisição pode ter mudado tudo.
     */
    public function transitionTo(
        Operation $operation,
        OperationStatus $target,
        User $actor,
        ?string $reason = null,
    ): Operation {
        $transitioned = DB::transaction(function () use ($operation, $target, $actor, $reason): Operation {
            $locked = Operation::query()
                ->whereKey($operation->getKey())
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof Operation) {
                throw new OperationLifecycleException('A operação não foi encontrada.');
            }

            $from = $locked->status;

            if (! $from->canTransitionTo($target)) {
                throw OperationLifecycleException::invalidTransition($from, $target);
            }

            $this->assertAuthorized($locked, $from, $target, $actor);
            $reason = $this->resolveReason($from, $target, $reason);

            if ($target->isTerminal()) {
                $this->assertNothingOpen($locked, $target);
            }

            // `saveQuietly` porque a Activity deste evento é a de lifecycle, logo
            // abaixo: deixar o `LogsActivity` gravar também produziria duas
            // linhas para a mesma transição, uma delas sem o motivo.
            $locked->forceFill(['status' => $target])->saveQuietly();

            activity('operations')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('lifecycle_transition')
                ->withProperties([
                    'from' => $from->value,
                    'to' => $target->value,
                    'reason' => $reason,
                ])
                ->log('operation_lifecycle_transition');

            return $locked;
        }, 3);

        // Quem chamou continua segurando a instância que passou -- a ação do
        // Filament renderiza a notificação a partir dela. Sem isto ela ficaria
        // exibindo a situação anterior até a próxima consulta.
        $operation->setRawAttributes($transitioned->getAttributes(), sync: true);

        return $operation;
    }

    /**
     * Trava a operação e confirma que ela aceita medição nova.
     *
     * É a outra metade do portão: o encerramento trava esta mesma linha para
     * conferir que não há medição aberta, e a criação trava para conferir que a
     * operação ainda está em andamento. Quem chamar precisa estar dentro da
     * transação que vai inserir a medição -- fora dela o lock é liberado antes
     * da inserção e a garantia deixa de existir, exatamente como em
     * {@see OperationContextMutationService::assertEmissionCanChange()}.
     */
    public function lockForNewMeasurement(int $operationId, User $actor): Operation
    {
        if (DB::transactionLevel() < 1) {
            throw new OperationLifecycleException('A criação de medição deve ocorrer dentro da transação que a grava.');
        }

        $operation = Operation::query()
            ->whereKey($operationId)
            ->lockForUpdate()
            ->first();

        if (! $operation instanceof Operation) {
            throw new OperationLifecycleException('A operação não foi encontrada.');
        }

        if (! $this->authorization->canCreateMeasurement($actor, $operation)) {
            throw OperationLifecycleException::newMeasurementsNotAllowed($operation->status);
        }

        return $operation;
    }

    /**
     * O que a reabertura devolve, no instante em que for confirmada.
     *
     * Só leitura: nenhuma delegação é destravada, nenhum `revoked_at` é tocado,
     * nenhuma responsabilidade é reatribuída. Os vínculos nunca foram desfeitos
     * -- o encerramento apenas impediu que produzissem trabalho novo --, e a
     * lista existe para que isso seja escolha explícita de quem reabre.
     */
    public function reopenPreflight(Operation $operation): OperationReopenPreflight
    {
        return new OperationReopenPreflight(
            $this->currentResponsibilities($operation),
            $this->delegationsThatWouldReturn($operation),
        );
    }

    /**
     * Reabrir é corrigir um encerramento, não operar: fica com administradores,
     * mesmo que o participante direto possa editar a operação. As demais
     * transições seguem a policy existente, sem permissão nova.
     */
    private function assertAuthorized(
        Operation $operation,
        OperationStatus $from,
        OperationStatus $target,
        User $actor,
    ): void {
        if ($from->isReopeningTo($target)) {
            if (! $this->authorization->isWorkflowAdministrator($actor)) {
                throw OperationLifecycleException::reopenNotAllowed();
            }

            return;
        }

        if (! $actor->can('update', $operation)) {
            throw new AuthorizationException('Você não possui permissão para alterar a situação desta operação.');
        }
    }

    /**
     * Cancelamento e reabertura são decisões que alguém vai precisar reconstruir
     * depois; ativar e concluir são o curso normal do trabalho e se explicam
     * pela própria transição.
     */
    private function requiresReason(OperationStatus $from, OperationStatus $target): bool
    {
        return $target === OperationStatus::Canceled || $from->isReopeningTo($target);
    }

    private function resolveReason(OperationStatus $from, OperationStatus $target, ?string $reason): ?string
    {
        $reason = Str::squish((string) $reason);

        if (! $this->requiresReason($from, $target)) {
            return $reason === '' ? null : $reason;
        }

        if ($reason === '') {
            throw OperationLifecycleException::reasonRequired();
        }

        if (Str::length($reason) > self::MAX_REASON_LENGTH) {
            throw new OperationLifecycleException(
                sprintf('O motivo deve ter no máximo %d caracteres.', self::MAX_REASON_LENGTH),
            );
        }

        return $reason;
    }

    /**
     * Nenhuma medição aberta -- a pré-condição que sustenta o desenho inteiro.
     *
     * As medições são travadas por id, na mesma ordem usada pela mutação de
     * contexto da operação e pela aprovação da Engenharia. Travar todas, e não
     * só as abertas, é o que mantém a ordem determinística: quem disputa estas
     * linhas as pega sempre na mesma sequência.
     */
    private function assertNothingOpen(Operation $operation, OperationStatus $target): void
    {
        $statuses = Measurement::query()
            ->where('operation_id', $operation->getKey())
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('status');

        $open = $statuses
            ->filter(fn (mixed $status): bool => in_array($status, Measurement::OPEN_STATUSES, true))
            ->count();

        if ($open > 0) {
            throw OperationLifecycleException::openMeasurements($target, $open);
        }
    }

    /**
     * Os responsáveis gravados hoje, pelo rótulo de domínio de cada etapa.
     *
     * @return list<array{responsibility: string, user: string}>
     */
    private function currentResponsibilities(Operation $operation): array
    {
        $userIds = [];

        foreach (MeasurementResponsibility::cases() as $responsibility) {
            $userId = $operation->responsibleUserIdFor($responsibility);

            if ($userId !== null) {
                $userIds[] = $userId;
            }
        }

        if (filled($operation->assigned_user_id)) {
            $userIds[] = (int) $operation->assigned_user_id;
        }

        if ($userIds === []) {
            return [];
        }

        $names = User::query()
            ->whereKey(array_values(array_unique($userIds)))
            ->pluck('name', 'id');

        $held = [];

        foreach (MeasurementResponsibility::cases() as $responsibility) {
            $userId = $operation->responsibleUserIdFor($responsibility);

            if ($userId === null) {
                continue;
            }

            $held[] = [
                'responsibility' => $responsibility->label(),
                'user' => (string) ($names[$userId] ?? '—'),
            ];
        }

        if (filled($operation->assigned_user_id)) {
            $held[] = [
                'responsibility' => 'Coordenação da operação',
                'user' => (string) ($names[(int) $operation->assigned_user_id] ?? '—'),
            ];
        }

        return $held;
    }

    /**
     * As delegações que voltarão a produzir autoridade sobre esta operação.
     *
     * Ficam de fora, pela própria avaliação de efetividade já usada em todo o
     * módulo: revogadas, expiradas, ainda não iniciadas, com delegante ou
     * delegado inelegível, sem a permissão exigida, sem o assignment que
     * sustenta a delegação, e as de escopo que não cobre nada. Entram tanto as
     * escopadas nesta operação quanto as de escopo amplo, que passam a alcançá-la
     * de novo -- a linha diz qual é qual.
     *
     * @return list<ResponsibilityDelegation>
     */
    private function delegationsThatWouldReturn(Operation $operation): array
    {
        return ResponsibilityDelegation::query()
            ->active()
            ->where(fn ($query) => $query
                ->where('scope_operation_id', $operation->getKey())
                ->orWhereNull('scope_operation_id'))
            ->with(['delegator', 'delegate', 'scopeOperation'])
            ->orderBy('ends_at')
            ->get()
            ->filter(fn (ResponsibilityDelegation $delegation): bool => $this->delegations
                ->effectiveness($delegation)
                ->isEffective())
            ->values()
            ->all();
    }
}
