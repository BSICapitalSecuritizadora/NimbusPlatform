<?php

namespace App\Services;

use App\Enums\OperationStatus;
use App\Exceptions\OperationLifecycleException;
use App\Models\Operation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class OperationResponsibilityService
{
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
}
