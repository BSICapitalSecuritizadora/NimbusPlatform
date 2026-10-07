<?php

namespace App\Services;

use App\Enums\OperationStatus;
use App\Exceptions\OperationLifecycleException;
use App\Models\Operation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OperationResponsibilityService
{
    /**
     * Substitui a lista "Notificar em caso de recusa" da operação.
     *
     * A lista só define quem recebe o aviso de recusa da Engenharia: não
     * concede leitura nem escrita sobre a operação ({@see Operation::hasParticipant()}).
     * Mesmo assim fica sob a governança dos responsáveis -- quem pode mudar, em
     * que situação da operação e quem pode entrar --, porque decide quem recebe
     * por e-mail código e título da operação, competência e nome do arquivo.
     *
     * As conferências só acontecem quando a lista muda de fato, como em
     * {@see self::assertCanChange()}: salvar a operação sem mexer na lista não
     * exige a permissão de gerir responsáveis, nem esbarra em operação
     * encerrada. Só quem entra precisa estar ativo e provisionado; quem saiu da
     * empresa continua removível.
     *
     * O pivot não dispara evento de model, então o Activitylog da operação não
     * o vê: a troca é registrada aqui, no mesmo log protegido das trocas de
     * responsável. A operação é travada antes do pivot (ordem "Operation
     * primeiro"), para duas edições simultâneas não calcularem a diferença
     * sobre a mesma lista antiga.
     *
     * @param  list<int|string>  $userIds
     * @param  bool  $onCreation  na criação vale a permissão de classe, como em
     *                            {@see self::assertCanAssignOnCreation()}: quem cria
     *                            ainda não participa da operação nova.
     * @return array{added: list<int>, removed: list<int>}
     *
     * @throws AuthorizationException
     * @throws OperationLifecycleException
     * @throws ValidationException
     */
    public function syncRejectionRecipients(User $actor, Operation $operation, array $userIds, bool $onCreation = false): array
    {
        $requested = array_values(array_unique(array_filter(
            array_map('intval', $userIds),
            fn (int $userId): bool => $userId > 0,
        )));

        return DB::transaction(function () use ($actor, $operation, $requested, $onCreation): array {
            $locked = Operation::query()->whereKey($operation->getKey())->lockForUpdate()->firstOrFail();
            $current = $locked->rejectionNotifyUsers()
                ->pluck('users.id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();
            $added = array_values(array_diff($requested, $current));
            $removed = array_values(array_diff($current, $requested));

            if ($added === [] && $removed === []) {
                return ['added' => [], 'removed' => []];
            }

            if (! $actor->can('manageResponsibilities', $onCreation ? Operation::class : $locked)) {
                throw new AuthorizationException('Você não possui permissão para alterar quem é notificado em caso de recusa nesta operação.');
            }

            if ($locked->status instanceof OperationStatus && ! $locked->status->allowsResponsibilityChanges()) {
                throw OperationLifecycleException::responsibilityChangesNotAllowed($locked->status);
            }

            $this->assertRejectionRecipientsAreOperational($added);

            $locked->rejectionNotifyUsers()->detach($removed);
            $locked->rejectionNotifyUsers()->attach($added);

            activity('operations')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('rejection_recipients_updated')
                ->withProperties([
                    'added' => $this->describeUsers($added),
                    'removed' => $this->describeUsers($removed),
                    'recipients' => $this->describeUsers($requested),
                ])
                ->log('operation_rejection_recipients_updated');

            return ['added' => $added, 'removed' => $removed];
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function assertCanChange(User $actor, Operation $operation, array $attributes): void
    {
        $changed = $this->changedResponsibilities($operation, $attributes);

        if ($changed !== [] && ! $actor->can('manageResponsibilities', $operation)) {
            throw new AuthorizationException('Você não possui permissão para alterar os responsáveis desta operação.');
        }

        $this->assertStatusAllowsChange($operation, $changed);
        $this->assertAssigneesAreOperational($changed);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function assertCanAssignOnCreation(User $actor, array $attributes): void
    {
        $assigned = collect(Operation::RESPONSIBILITY_FIELDS)
            ->filter(fn (string $field): bool => filled($attributes[$field] ?? null))
            ->mapWithKeys(fn (string $field): array => [$field => (int) $attributes[$field]])
            ->all();

        if ($assigned !== [] && ! $actor->can('manageResponsibilities', Operation::class)) {
            throw new AuthorizationException('Você não possui permissão para definir os responsáveis desta operação.');
        }

        $this->assertAssigneesAreOperational($assigned);
    }

    /**
     * Quais responsabilidades esta gravação está de fato trocando.
     *
     * Só o que muda importa. Uma operação cujo responsável de Engenharia foi
     * desligado meses atrás continua editável em qualquer outro campo: o
     * responsável antigo não está sendo atribuído de novo, está apenas
     * permanecendo, e permanecer é histórico -- não é escolha nova.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, int> campo => id do novo responsável
     */
    private function changedResponsibilities(Operation $operation, array $attributes): array
    {
        return collect(Operation::RESPONSIBILITY_FIELDS)
            ->filter(fn (string $field): bool => array_key_exists($field, $attributes)
                && (int) ($operation->exists ? $operation->getOriginal($field) : null) !== (int) ($attributes[$field] ?? 0))
            ->mapWithKeys(fn (string $field): array => [$field => (int) ($attributes[$field] ?? 0)])
            ->filter(fn (int $userId): bool => $userId > 0)
            ->all();
    }

    /**
     * Operação encerrada não distribui trabalho novo.
     *
     * A situação lida é a **anterior** à gravação, não a que está sendo salva:
     * a pergunta é em que estado a operação estava quando alguém tentou trocar
     * o responsável. Os responsáveis já gravados continuam intactos -- o que se
     * recusa aqui é a escolha nova, nunca o histórico.
     *
     * @param  array<string, int>  $changed
     */
    private function assertStatusAllowsChange(Operation $operation, array $changed): void
    {
        if ($changed === [] || ! $operation->exists) {
            return;
        }

        $status = $operation->getOriginal('status');

        if ($status instanceof OperationStatus && ! $status->allowsResponsibilityChanges()) {
            throw OperationLifecycleException::responsibilityChangesNotAllowed($status);
        }
    }

    /**
     * Ninguém assume responsabilidade nova sem estar ativo e provisionado.
     *
     * O formulário já não oferece quem não pode, mas o formulário é sugestão: o
     * id chega pela requisição e pode ser trocado. A regra tem de existir aqui,
     * onde a gravação passa de verdade -- e é a mesma regra de elegibilidade que
     * decide a efetividade das delegações e os destinatários de notificação,
     * lida do próprio {@see User}.
     *
     * @param  array<string, int>  $assignments
     */
    private function assertAssigneesAreOperational(array $assignments): void
    {
        if ($assignments === []) {
            return;
        }

        $eligible = User::query()
            ->operational()
            ->whereKey(array_values(array_unique($assignments)))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $rejected = array_filter(
            $assignments,
            fn (int $userId): bool => ! in_array($userId, $eligible, true),
        );

        if ($rejected === []) {
            return;
        }

        throw ValidationException::withMessages(array_map(
            fn (): string => 'Este usuário não está disponível para novas responsabilidades. '
                .'Usuários precisam estar ativos e provisionados na plataforma.',
            $rejected,
        ));
    }

    /**
     * A mesma elegibilidade dos responsáveis, aplicada só a quem entra na lista:
     * o formulário oferece apenas usuários elegíveis, mas o id chega pela
     * requisição e pode ser trocado.
     *
     * @param  list<int>  $added
     */
    private function assertRejectionRecipientsAreOperational(array $added): void
    {
        if ($added === []) {
            return;
        }

        $eligible = User::query()
            ->operational()
            ->whereKey($added)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $ineligible = array_values(array_diff($added, $eligible));

        if ($ineligible === []) {
            return;
        }

        $names = User::query()->whereKey($ineligible)->orderBy('name')->pluck('name');

        throw ValidationException::withMessages([
            'rejectionNotifyUsers' => sprintf(
                'Só usuários ativos e provisionados na plataforma podem ser notificados em caso de recusa. Retire da seleção %s.',
                $names->isEmpty() ? 'os usuários que não existem mais' : $names->implode(', '),
            ),
        ]);
    }

    /**
     * @param  list<int>  $userIds
     * @return list<array{id: int, name: string}>
     */
    private function describeUsers(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return User::query()
            ->whereKey($userIds)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user): array => ['id' => (int) $user->id, 'name' => (string) $user->name])
            ->values()
            ->all();
    }
}
