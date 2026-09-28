<?php

namespace App\Filament\Support;

use App\Models\User;
use BackedEnum;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * Autoriza o Resource pela policy do model -- e só por ela.
 *
 * No Filament 5 as ações padrão (DeleteAction da página, DeleteBulkAction,
 * RestoreAction, o acesso à edição) são autorizadas por
 * `get*AuthorizationResponse()`, nunca pelos `can*()` sobrescritos no Resource.
 * Sem policy essas respostas são "permitido" para todo mundo, que é como um
 * usuário só com visualização apagava contratos em massa. Com este trait todo
 * `can*()` e todo `get*AuthorizationResponse()` do Resource chegam ao mesmo
 * método da policy, e a regra fica num lugar só.
 *
 * A policy é chamada diretamente, sem passar pelo Gate, por um motivo: o
 * `Gate::before` do super-admin responde "sim" antes de qualquer policy ser
 * consultada. Para permissão isso continua valendo -- dentro da policy,
 * `$user->can('x')` passa pelo Gate, e o super-admin passa. As guardas de
 * integridade, porém, não são permissão: uma unidade congelada num ciclo não
 * pode ser apagada por ninguém, e sem isso o super-admin veria o botão e
 * receberia o erro de constraint do banco.
 *
 * Uma ability sem método na policy é negada. Nada é liberado por omissão.
 */
trait AuthorizesThroughModelPolicy
{
    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        if (static::shouldSkipAuthorization()) {
            return Response::allow();
        }

        $user = Filament::auth()->user();
        $policy = Gate::getPolicyFor(static::getModel());

        $ability = match (true) {
            $action instanceof BackedEnum => (string) $action->value,
            $action instanceof UnitEnum => $action->name,
            default => $action,
        };

        if ((! $user instanceof User) || (! is_object($policy)) || (! method_exists($policy, $ability))) {
            return Response::deny();
        }

        $result = ($record === null)
            ? $policy->{$ability}($user)
            : $policy->{$ability}($user, $record);

        if ($result instanceof Response) {
            return $result;
        }

        return $result ? Response::allow() : Response::deny();
    }
}
