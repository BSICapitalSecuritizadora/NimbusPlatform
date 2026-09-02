<?php

namespace App\Services;

use App\Enums\MeasurementResponsibility;
use App\Models\Operation;
use App\Models\ResponsibilityDelegation;
use App\Models\User;
use App\Support\Users\UserReactivationPreflight;
use Illuminate\Validation\ValidationException;

/**
 * Entrada e saída de um usuário da operação, sem tocar no histórico.
 *
 * Desativar não revoga delegação, não solta responsabilidade e não apaga nada:
 * muda `is_active`, e o resto do sistema já sabe o que fazer com isso -- o login
 * cai, a delegação vira inefetiva, o SLA para de escolher a pessoa como
 * destinatário. Reativar faz o inverso, e é justamente por isso que precisa de
 * aviso: os vínculos continuaram lá o tempo todo, e voltam a valer no mesmo
 * instante.
 */
class UserLifecycleService
{
    public function __construct(private readonly ResponsibilityDelegationService $delegations) {}

    /**
     * Retira o usuário da operação.
     *
     * Só `is_active`. O Activitylog do próprio modelo registra a mudança -- ele
     * já observa esta coluna --, então não há log manual aqui: dois registros da
     * mesma alteração seriam pior que nenhum.
     */
    public function deactivate(User $user, User $actor): void
    {
        $this->assertNotSelf($user, $actor, 'Você não pode desativar o seu próprio usuário.');

        if (! $user->isActive()) {
            return;
        }

        $user->forceFill(['is_active' => false])->save();
    }

    public function reactivate(User $user, User $actor): void
    {
        if ($user->isActive()) {
            return;
        }

        $user->forceFill(['is_active' => true])->save();
    }

    /**
     * O que volta a valer se este usuário for reativado agora.
     *
     * Não é o inventário de tudo o que já esteve ligado a ele: é o efeito real da
     * reativação neste instante. Responsabilidade direta volta sempre, porque a
     * coluna nunca deixou de apontar para ele. Delegação só volta se ela, sozinha,
     * estivesse presa apenas por este usuário estar inativo -- o que é decidido
     * relendo a efetividade com esse único predicado relaxado, nunca gravando
     * estado intermediário.
     */
    public function reactivationPreflight(User $user): UserReactivationPreflight
    {
        return new UserReactivationPreflight(
            $this->directResponsibilities($user),
            $this->delegationsThatWouldReturn($user),
        );
    }

    /**
     * As responsabilidades diretas que o usuário ainda detém, por operação.
     *
     * Uma consulta só: as sete colunas de responsabilidade vivem na própria
     * `operations`, então basta encontrar as operações em que ele aparece em
     * qualquer uma delas e ler quais são, em memória.
     *
     * @return list<array{operation: string, responsibility: string}>
     */
    private function directResponsibilities(User $user): array
    {
        $operations = Operation::query()
            ->where(function ($query) use ($user): void {
                foreach (MeasurementResponsibility::cases() as $responsibility) {
                    $query->orWhere($responsibility->operationColumn(), $user->getKey());
                }

                $query->orWhere('assigned_user_id', $user->getKey());
            })
            ->orderBy('code')
            ->get(['id', 'code', 'title', 'assigned_user_id', ...array_map(
                fn (MeasurementResponsibility $responsibility): string => $responsibility->operationColumn(),
                MeasurementResponsibility::cases(),
            )]);

        $held = [];

        foreach ($operations as $operation) {
            $label = $operation->code ?? ('Operação #'.$operation->getKey());

            foreach (MeasurementResponsibility::cases() as $responsibility) {
                if ((int) $operation->getAttribute($responsibility->operationColumn()) === (int) $user->getKey()) {
                    $held[] = ['operation' => $label, 'responsibility' => $responsibility->label()];
                }
            }

            if ((int) $operation->assigned_user_id === (int) $user->getKey()) {
                $held[] = ['operation' => $label, 'responsibility' => 'Coordenação da operação'];
            }
        }

        return $held;
    }

    /**
     * As delegações que voltariam a ser efetivas -- e só elas.
     *
     * Fora da conta ficam, por construção da própria avaliação: revogadas,
     * expiradas, ainda não iniciadas, as que continuam presas por outro motivo
     * (permissão perdida, responsabilidade perdida, escopo que não cobre nada) e
     * as que dependem de um segundo participante que também está inelegível.
     *
     * @return list<ResponsibilityDelegation>
     */
    private function delegationsThatWouldReturn(User $user): array
    {
        // Reativar quem já está ativo não devolve nada, e sem esta saída a
        // simulação abaixo seria idêntica à avaliação real -- listaria como
        // "voltaria a valer" tudo o que já vale.
        if ($user->isActive()) {
            return [];
        }

        $candidates = ResponsibilityDelegation::query()
            ->whereNull('revoked_at')
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>=', now())
            ->where(function ($query) use ($user): void {
                $query->where('delegator_user_id', $user->getKey())
                    ->orWhere('delegate_user_id', $user->getKey());
            })
            ->with(['delegator', 'delegate', 'scopeOperation'])
            ->orderBy('id')
            ->get();

        // Uma avaliação por delegação, não duas. O usuário é delegante ou delegado
        // de cada candidata e está inativo, então toda delegação que passa na
        // simulação está hoje inefetiva por definição -- conferir isso de novo
        // custaria outra rodada de consultas para reencontrar o que já se sabe.
        return $candidates
            ->filter(fn (ResponsibilityDelegation $delegation): bool => $this->delegations
                ->effectivenessAssumingActive($delegation, (int) $user->getKey())
                ->isEffective())
            ->values()
            ->all();
    }

    private function assertNotSelf(User $user, User $actor, string $message): void
    {
        if ((int) $user->getKey() === (int) $actor->getKey()) {
            throw ValidationException::withMessages(['user' => $message]);
        }
    }
}
