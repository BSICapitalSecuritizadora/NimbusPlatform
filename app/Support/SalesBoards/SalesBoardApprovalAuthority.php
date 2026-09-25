<?php

declare(strict_types=1);

namespace App\Support\SalesBoards;

use App\Actions\Emissions\HomologatePuCurve;
use App\Enums\AccessPermission;
use App\Exceptions\SalesBoardMakerCheckerException;
use App\Models\SalesBoardBuilderReview;
use App\Models\SalesBoardManagementReview;
use App\Models\SalesBoardRolloutHomologation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Quem pode concluir o que foi preparado no Quadro de Vendas.
 *
 * Duas regras, e as duas valem na tela e no serviço. A tela esconde e explica; o
 * serviço recusa, porque a tela não é segurança: um `mountAction` forjado, um
 * comando ou um job chegam ao serviço sem passar por ela.
 *
 * 1. **permissão própria.** Decidir não conformidades, aprovar e publicar,
 *    devolver à construtora, atestar os impactos da homologação, aprová-la,
 *    ativar a automação e retornar ao legado exigem `sales-boards.approve`.
 *    `sales-boards.update` continua sendo de quem opera a competência;
 * 2. **maker/checker.** Quem enviou a validação da construtora não aprova a
 *    publicação daquela rodada, e quem abriu a homologação não a aprova nem
 *    ativa a automação com base nela. Super admin é isento, exatamente como no
 *    PU ({@see HomologatePuCurve}).
 *
 * Os autores são relidos do banco pela chave, e não da instância recebida: a
 * tela de rollout carrega a homologação com só algumas colunas, e confiar num
 * atributo ausente seria tratar "não sei quem abriu" como "ninguém abriu".
 */
final class SalesBoardApprovalAuthority
{
    public static function holds(?User $user): bool
    {
        return $user?->can(AccessPermission::SalesBoardsApprove->value) ?? false;
    }

    /**
     * @throws AuthorizationException
     */
    public static function authorize(?User $user): void
    {
        if (! self::holds($user)) {
            throw new AuthorizationException(sprintf(
                'Você não possui permissão para esta etapa do Quadro de Vendas. Ela é da Gestão e exige a permissão "%s".',
                AccessPermission::SalesBoardsApprove->label(),
            ));
        }
    }

    public static function isExemptFromSegregation(User $user): bool
    {
        return $user->hasRole('super-admin');
    }

    /**
     * Por que este usuário não pode aprovar e publicar a análise -- ou `null`,
     * se pode.
     */
    public static function managementApprovalConflict(
        User $approver,
        SalesBoardManagementReview $review,
    ): ?SalesBoardMakerCheckerException {
        $submitterId = SalesBoardBuilderReview::query()
            ->whereKey($review->sales_board_builder_review_id)
            ->value('submitted_by_user_id');

        return self::isMaker($approver, $submitterId)
            ? SalesBoardMakerCheckerException::approverSubmittedBuilderReview()
            : null;
    }

    public static function homologationApprovalConflict(
        User $approver,
        SalesBoardRolloutHomologation $homologation,
    ): ?SalesBoardMakerCheckerException {
        return self::isMaker($approver, self::homologationOpenerId($homologation))
            ? SalesBoardMakerCheckerException::approverOpenedHomologation()
            : null;
    }

    public static function activationConflict(
        User $activator,
        SalesBoardRolloutHomologation $homologation,
    ): ?SalesBoardMakerCheckerException {
        return self::isMaker($activator, self::homologationOpenerId($homologation))
            ? SalesBoardMakerCheckerException::activatorOpenedHomologation()
            : null;
    }

    /**
     * @throws AuthorizationException
     * @throws SalesBoardMakerCheckerException
     */
    public static function assertMayApproveManagementReview(?User $approver, SalesBoardManagementReview $review): void
    {
        self::authorize($approver);
        self::refuse(self::managementApprovalConflict($approver, $review));
    }

    /**
     * @throws AuthorizationException
     * @throws SalesBoardMakerCheckerException
     */
    public static function assertMayApproveHomologation(?User $approver, SalesBoardRolloutHomologation $homologation): void
    {
        self::authorize($approver);
        self::refuse(self::homologationApprovalConflict($approver, $homologation));
    }

    /**
     * @throws AuthorizationException
     * @throws SalesBoardMakerCheckerException
     */
    public static function assertMayActivate(?User $activator, SalesBoardRolloutHomologation $homologation): void
    {
        self::authorize($activator);
        self::refuse(self::activationConflict($activator, $homologation));
    }

    private static function homologationOpenerId(SalesBoardRolloutHomologation $homologation): mixed
    {
        return SalesBoardRolloutHomologation::query()
            ->whereKey($homologation->getKey())
            ->value('created_by_user_id');
    }

    /**
     * Sem autor identificado não há o que segregar -- o mesmo critério do PU
     * para o que chega sem usuário.
     */
    private static function isMaker(User $checker, mixed $makerId): bool
    {
        if ($makerId === null || self::isExemptFromSegregation($checker)) {
            return false;
        }

        return (int) $makerId === (int) $checker->getKey();
    }

    private static function refuse(?SalesBoardMakerCheckerException $conflict): void
    {
        if ($conflict !== null) {
            throw $conflict;
        }
    }
}
