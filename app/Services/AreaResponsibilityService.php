<?php

namespace App\Services;

use App\Enums\AccessPermission;
use App\Enums\BusinessArea;
use App\Models\Area;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Quem responde por cada área de negócio.
 *
 * A responsabilidade não concede permissão: ela só habilita as regras próprias
 * de cada área ({@see BusinessArea::responsibleRule()}), sempre somadas às
 * permissões do perfil. Toda troca de responsáveis fica no log `areas`.
 */
class AreaResponsibilityService
{
    public const LOG_NAME = 'areas';

    public function isResponsible(?int $userId, BusinessArea $area): bool
    {
        if ($userId === null) {
            return false;
        }

        return DB::table('area_user')
            ->join('areas', 'areas.id', '=', 'area_user.area_id')
            ->where('areas.code', $area->value)
            ->where('area_user.user_id', $userId)
            ->exists();
    }

    /**
     * Usuários que podem ser escolhidos agora, mais os já cadastrados na área:
     * um responsável que ficou inativo continua na lista para poder ser removido.
     *
     * @return array<int, string>
     */
    public function selectableUserOptions(Area $area): array
    {
        return User::query()
            ->where(fn ($query) => $query->operational())
            ->orWhereIn('id', $area->responsibles()->pluck('users.id'))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Substitui os responsáveis da área pela lista informada.
     *
     * @param  list<int|string>  $userIds
     * @return array{added: list<int>, removed: list<int>}
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function syncResponsibles(Area $area, array $userIds, User $actor): array
    {
        if (! $actor->can(AccessPermission::AreasManage->value)) {
            throw new AuthorizationException('Você não possui permissão para definir responsáveis de áreas.');
        }

        $requested = array_values(array_unique(array_map('intval', $userIds)));

        return DB::transaction(function () use ($area, $requested, $actor): array {
            $locked = Area::query()->whereKey($area->id)->lockForUpdate()->firstOrFail();
            $current = $locked->responsibles()->pluck('users.id')->map(fn (mixed $id): int => (int) $id)->all();
            $added = array_values(array_diff($requested, $current));
            $removed = array_values(array_diff($current, $requested));

            $eligible = User::query()->operational()->whereKey($added)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
            $ineligible = array_values(array_diff($added, $eligible));

            if ($ineligible !== []) {
                throw ValidationException::withMessages([
                    'responsibles' => 'Só usuários ativos e liberados podem ser cadastrados como responsáveis.',
                ]);
            }

            if ($added === [] && $removed === []) {
                return ['added' => [], 'removed' => []];
            }

            $locked->responsibles()->detach($removed);
            $locked->responsibles()->attach(array_fill_keys($added, ['assigned_by' => $actor->id]));

            activity(self::LOG_NAME)
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('responsibles_updated')
                ->withProperties([
                    'area' => $locked->code->value,
                    'added' => $this->describeUsers($added),
                    'removed' => $this->describeUsers($removed),
                    'responsibles' => $this->describeUsers($requested),
                ])
                ->log('area_responsibles_updated');

            return ['added' => $added, 'removed' => $removed];
        });
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
