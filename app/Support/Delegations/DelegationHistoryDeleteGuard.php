<?php

namespace App\Support\Delegations;

use App\Models\Operation;
use App\Models\ResponsibilityDelegation;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

/**
 * Recusa, na interface, a exclusão de quem aparece em delegação de
 * responsabilidade -- antes de o banco recusar por chave estrangeira.
 *
 * As chaves de `responsibility_delegations` são RESTRICT e os modelos recusam no
 * evento `deleting`; as duas camadas devolvem uma exceção, o que resolve a
 * integridade e não a leitura. Aqui a recusa vira mensagem, e o lote inteiro é
 * abortado antes da primeira exclusão: nada de apagar metade da seleção e depois
 * estourar no registro protegido.
 *
 * A consulta é uma só para a seleção inteira, e diz quais registros bloqueiam --
 * a mensagem precisa nomeá-los para que a pessoa saiba o que tirar da seleção.
 */
class DelegationHistoryDeleteGuard
{
    /**
     * @param  iterable<Model>  $users
     */
    public static function haltForUsers(iterable $users, Action $action): void
    {
        self::halt(
            $users,
            fn (array $ids): array => ResponsibilityDelegation::query()
                ->whereIn('delegator_user_id', $ids)
                ->orWhereIn('delegate_user_id', $ids)
                ->get(['delegator_user_id', 'delegate_user_id'])
                ->flatMap(fn (ResponsibilityDelegation $delegation): array => [
                    (int) $delegation->delegator_user_id,
                    (int) $delegation->delegate_user_id,
                ])
                ->unique()
                ->all(),
            fn (User $user): string => (string) $user->name,
            'Usuário não excluído.',
            'Nenhum usuário excluído.',
            $action,
        );
    }

    /**
     * @param  iterable<Model>  $operations
     */
    public static function haltForOperations(iterable $operations, Action $action): void
    {
        self::halt(
            $operations,
            fn (array $ids): array => ResponsibilityDelegation::query()
                ->whereIn('scope_operation_id', $ids)
                ->pluck('scope_operation_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->unique()
                ->all(),
            fn (Operation $operation): string => (string) ($operation->code ?? $operation->getKey()),
            'Operação não excluída.',
            'Nenhuma operação excluída.',
            $action,
        );
    }

    /**
     * O título fala do que aconteceu com a seleção, não de quantos registros
     * bloquearam: num lote de dez em que um bloqueia, nenhum foi excluído, e o
     * singular sugeriria que os outros nove foram.
     *
     * @param  iterable<Model>  $records
     * @param  callable(list<int>): list<int>  $blockedIds
     * @param  callable(Model): string  $label
     */
    private static function halt(
        iterable $records,
        callable $blockedIds,
        callable $label,
        string $singleTitle,
        string $batchTitle,
        Action $action,
    ): void {
        $byId = [];

        foreach ($records as $record) {
            $byId[(int) $record->getKey()] = $record;
        }

        if ($byId === []) {
            return;
        }

        $blocked = array_intersect(array_keys($byId), $blockedIds(array_keys($byId)));

        if ($blocked === []) {
            return;
        }

        Notification::make()
            ->danger()
            ->title(count($byId) === 1 ? $singleTitle : $batchTitle)
            ->body('Histórico de delegações de responsabilidade impede a exclusão de: '
                .implode(', ', array_map(fn (int $id): string => $label($byId[$id]), $blocked))
                .'. Delegação revogada continua sendo histórico e também bloqueia.')
            ->persistent()
            ->send();

        $action->halt();
    }
}
