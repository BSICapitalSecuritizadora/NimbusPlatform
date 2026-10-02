<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\Enums\SalesBoardRolloutRecipientRole;
use App\Exceptions\SalesBoardRolloutException;
use App\Models\Emission;
use App\Models\SalesBoardRolloutRecipient;
use App\Models\User;
use App\Support\SalesBoards\SalesBoardAccess;
use Illuminate\Support\Facades\DB;

/**
 * Quem está configurado para receber os avisos de uma Emissão, e quem ainda
 * pode recebê-los.
 *
 * A distinção entre configurado e ativo é o ponto inteiro desta classe. Uma
 * pessoa configurada há um ano pode ter saído da empresa; notificá-la seria
 * mandar aviso operacional para uma conta desativada, e a automação seguiria
 * achando que avisou alguém. Aqui a configuração é durável e a resolução é
 * sempre sobre quem está operacional **agora**.
 *
 * Definir os responsáveis é preparo da homologação: adicionar e remover exigem
 * um ator identificado que opere a competência
 * ({@see SalesBoardAccess::authorizeOperation()}), conferido antes de qualquer
 * gravação.
 */
class SalesBoardRolloutRecipientDirectory
{
    /**
     * Os destinatários ativos de um papel.
     *
     * Reaproveita `User::isOperational()` -- ativo e aprovado --, que é o
     * lifecycle que o resto do sistema já usa. Um segundo conceito de "usuário
     * válido" aqui divergiria do primeiro na primeira mudança.
     *
     * @return list<User>
     */
    public function activeFor(Emission $emission, SalesBoardRolloutRecipientRole $role): array
    {
        return SalesBoardRolloutRecipient::query()
            ->where('emission_id', $emission->getKey())
            ->forRole($role)
            ->with('user')
            ->get()
            ->map(fn (SalesBoardRolloutRecipient $recipient): ?User => $recipient->user)
            ->filter(fn (?User $user): bool => $user?->isOperational() ?? false)
            /**
             * Deduplicado por usuário: a unique impede a mesma linha, mas uma
             * importação ou uma migração futura poderia trazer o mesmo usuário
             * por caminhos diferentes, e ninguém precisa receber o aviso duas
             * vezes.
             */
            ->unique(fn (User $user): int => (int) $user->getKey())
            ->values()
            ->all();
    }

    /**
     * A Emissão tem os dois papéis cobertos?
     */
    public function assertConfigured(Emission $emission): void
    {
        foreach (SalesBoardRolloutRecipientRole::cases() as $role) {
            if ($this->activeFor($emission, $role) === []) {
                throw SalesBoardRolloutException::recipientsMissing(mb_strtolower($role->label()));
            }
        }
    }

    /**
     * Registra um destinatário.
     *
     * Só usuário operacional entra. Configurar alguém que não pode acessar o
     * sistema criaria um destinatário que nunca receberia nada e daria a
     * impressão de que o papel está coberto.
     */
    public function add(
        Emission $emission,
        SalesBoardRolloutRecipientRole $role,
        User $user,
        ?User $actor,
    ): SalesBoardRolloutRecipient {
        if ($actor === null) {
            throw SalesBoardRolloutException::actorRequired();
        }

        SalesBoardAccess::authorizeOperation($actor);

        if (! $user->isOperational()) {
            throw SalesBoardRolloutException::recipientNotOperational();
        }

        return SalesBoardRolloutRecipient::query()->firstOrCreate(
            [
                'emission_id' => $emission->getKey(),
                'role' => $role,
                'user_id' => $user->getKey(),
            ],
            ['created_by_user_id' => $actor->getKey()],
        );
    }

    /**
     * Tira um destinatário da lista de avisos.
     *
     * A remoção trava a Emissão antes de apagar, na mesma ordem da ativação --
     * Emissão primeiro. A ativação confere, com a Emissão travada, que os dois
     * papéis têm alguém operacional; uma remoção que não esperasse por ela
     * poderia apagar o último responsável entre a conferência e o commit, e a
     * automação nasceria ativada sem ninguém para avisar. Travando a mesma
     * linha, as duas se serializam: ou a remoção vem antes e a ativação é
     * recusada pela falta de responsável, ou a ativação vem antes e a remoção
     * acontece depois dela, já com a automação ligada.
     *
     * O destinatário é relido sob o lock: removido por outra pessoa nesse
     * intervalo, não há o que apagar -- e a trilha não ganha uma segunda
     * exclusão da mesma linha.
     */
    public function remove(SalesBoardRolloutRecipient $recipient, ?User $actor): void
    {
        if ($actor === null) {
            throw SalesBoardRolloutException::actorRequired();
        }

        SalesBoardAccess::authorizeOperation($actor);

        $emissionId = SalesBoardRolloutRecipient::query()
            ->whereKey($recipient->getKey())
            ->value('emission_id');

        if ($emissionId === null) {
            return;
        }

        DB::transaction(function () use ($recipient, $emissionId): void {
            Emission::query()
                ->whereKey($emissionId)
                ->lockForUpdate()
                ->firstOrFail();

            SalesBoardRolloutRecipient::query()
                ->whereKey($recipient->getKey())
                ->lockForUpdate()
                ->first()
                ?->delete();
        });
    }
}
