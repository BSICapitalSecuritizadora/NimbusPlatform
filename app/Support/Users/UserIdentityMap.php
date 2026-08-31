<?php

namespace App\Support\Users;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Mapa de identidade de User com vida útil de uma execução.
 *
 * Resolve cada `user_id` para UMA instância, carregada uma vez com as relações
 * que o consumidor declara precisar. Existe para quebrar o padrão de buscar o
 * mesmo responsável -- e, atrás dele, roles e permissions -- outra vez a cada
 * medição avaliada.
 *
 * O que ele NÃO é: um cache. Nasce vazio a cada execução do processo que o cria
 * e morre com ela; nada é escrito em Redis, em cache de aplicação, em estado
 * estático ou em sessão. Duas execuções consecutivas enxergam o banco de novo,
 * então RBAC revogado, usuário desativado ou delegação alterada entre elas são
 * percebidos normalmente. A única coisa que ele suprime é repetir a MESMA
 * pergunta dentro da MESMA execução, onde a resposta não pode ter mudado.
 *
 * Só a identidade do usuário é reutilizável desta forma. Autorização e
 * delegação efetiva dependem de operação e responsabilidade, não só de quem é o
 * usuário, e continuam sendo resolvidas por contexto.
 */
final class UserIdentityMap
{
    /** @var array<int, ?User> */
    private array $users = [];

    /** @param list<string> $relations relações carregadas junto de cada usuário */
    public function __construct(private readonly array $relations = []) {}

    /**
     * O usuário de `$id`, ou null se ele não existe.
     *
     * A ausência também é lembrada: um id órfão não é reconsultado a cada
     * medição que o referencia.
     */
    public function get(?int $id): ?User
    {
        if ($id === null) {
            return null;
        }

        $this->load([$id]);

        return $this->users[$id];
    }

    /**
     * Carrega em UMA consulta todos os ids que ainda não estão no mapa.
     *
     * @param  iterable<int|string|null>  $ids
     */
    public function load(iterable $ids): void
    {
        $missing = [];

        foreach ($ids as $id) {
            if ($id === null) {
                continue;
            }

            $id = (int) $id;

            if (! array_key_exists($id, $this->users)) {
                $missing[$id] = $id;
            }
        }

        if ($missing === []) {
            return;
        }

        /** @var Collection<int, User> $loaded */
        $loaded = User::query()
            ->with($this->relations)
            ->whereKey(array_values($missing))
            ->get();

        foreach ($missing as $id) {
            $this->users[$id] = null;
        }

        foreach ($loaded as $user) {
            $this->users[(int) $user->getKey()] = $user;
        }
    }

    /** Quantos usuários distintos a execução precisou resolver. */
    public function size(): int
    {
        return count($this->users);
    }
}
