<?php

declare(strict_types=1);

namespace App\Support\SalesBoards;

use App\DTOs\SalesBoards\BuilderReviewerIdentity;
use App\Enums\AccessPermission;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Quem pode ver o Quadro de Vendas e quem pode preparar uma competência.
 *
 * Irmã de {@see SalesBoardApprovalAuthority}: lá ficam os atos que concluem o
 * que foi preparado, e que são da Gestão; aqui ficam a leitura e o preparo. As
 * regras valem na tela e no serviço. A tela esconde e explica; o serviço
 * recusa, porque a tela não é segurança: um `mountAction` forjado, um comando
 * ou um job chegam ao serviço sem passar por ela.
 *
 * 1. **ver** exige `sales-boards.view` **e** `emissions.view`. O Quadro é um
 *    recorte da Emissão -- os itens do módulo ficam sob "Emissões" na
 *    navegação --, e as telas dele carregam a Emissão e a obra no registro que
 *    o Filament devolve a quem chamar `getRecord()`. Quem não enxerga a Emissão
 *    não pode recebê-la por esse caminho;
 * 2. **preparar** -- abrir e editar a validação da construtora, conduzir a
 *    homologação, analisar diferenças e definir os responsáveis -- exige
 *    `sales-boards.update`, a permissão de quem opera a competência;
 * 3. abrir a análise da Gestão, verificar a fonte e cancelar a competência
 *    aceitam `sales-boards.update` **ou** `sales-boards.approve`. A Gestão
 *    precisa abrir a análise que ela mesma vai decidir: abrir materializa as
 *    pendências, não prepara dado, e não entra no maker/checker, que compara o
 *    aprovador com quem enviou a validação;
 * 4. **congelar** uma competência -- gerar o ciclo e a versão 1 -- exige
 *    `sales-boards.create`, a mesma regra que mostra "Congelar competência" na
 *    tela; recalcular é preparo e segue a regra 2.
 *
 * O ator é sempre recebido, nunca lido de `auth()`: o serviço não sabe se quem
 * chama é a tela, um comando ou um job, e um ator nulo é recusado -- a não ser
 * onde o próprio serviço o trata como automação, de forma explícita.
 */
final class SalesBoardAccess
{
    public static function canView(?User $user): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        return $user->can(AccessPermission::SalesBoardsView->value)
            && $user->can(AccessPermission::EmissionsView->value);
    }

    public static function canOperate(?User $user): bool
    {
        return $user?->can(AccessPermission::SalesBoardsUpdate->value) ?? false;
    }

    public static function canOperateOrApprove(?User $user): bool
    {
        return self::canOperate($user) || SalesBoardApprovalAuthority::holds($user);
    }

    public static function canGenerate(?User $user): bool
    {
        return $user?->can(AccessPermission::SalesBoardsCreate->value) ?? false;
    }

    /**
     * @throws AuthorizationException
     */
    public static function authorizeOperation(?User $user): void
    {
        if (! self::canOperate($user)) {
            throw new AuthorizationException(sprintf(
                'Você não possui permissão para esta etapa do Quadro de Vendas. Ela é de quem opera a competência e exige a permissão "%s".',
                AccessPermission::SalesBoardsUpdate->label(),
            ));
        }
    }

    /**
     * @throws AuthorizationException
     */
    public static function authorizeGeneration(?User $user): void
    {
        if (! self::canGenerate($user)) {
            throw new AuthorizationException(sprintf(
                'Você não possui permissão para congelar competência do Quadro de Vendas. Ela exige a permissão "%s".',
                AccessPermission::SalesBoardsCreate->label(),
            ));
        }
    }

    /**
     * @throws AuthorizationException
     */
    public static function authorizeOperationOrApproval(?User $user): void
    {
        if (! self::canOperateOrApprove($user)) {
            throw new AuthorizationException(sprintf(
                'Você não possui permissão para esta etapa do Quadro de Vendas. Ela exige a permissão "%s" ou "%s".',
                AccessPermission::SalesBoardsUpdate->label(),
                AccessPermission::SalesBoardsApprove->label(),
            ));
        }
    }

    /**
     * Quem responde pela construtora precisa poder operar a competência.
     *
     * A identidade abstrata da validação ({@see BuilderReviewerIdentity}) é o
     * que o domínio da revisão conhece, e é ela que autoriza: o operador interno
     * é relido do banco pela chave, e não aceito como veio, para que uma
     * permissão revogada no meio do preenchimento valha já no clique seguinte.
     * Sem chave não há quem autorizar, e a recusa é a mesma de quem não tem a
     * permissão.
     *
     * A identidade externa falha fechado. O canal externo da construtora ainda
     * não existe, nenhum caminho de código a produz, e quando existir a
     * autorização dele precisa ser desenhada à parte -- ela não herda a regra do
     * painel interno.
     *
     * @throws AuthorizationException
     */
    public static function authorizeBuilderReviewer(BuilderReviewerIdentity $reviewer): void
    {
        if ($reviewer->isExternal()) {
            throw new AuthorizationException(
                'A validação da construtora só pode ser registrada por um operador interno: o canal externo da construtora ainda não existe.'
            );
        }

        $user = $reviewer->internalUserId === null
            ? null
            : User::query()->find($reviewer->internalUserId);

        self::authorizeOperation($user);
    }
}
