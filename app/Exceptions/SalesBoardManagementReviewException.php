<?php

namespace App\Exceptions;

use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardNonconformityDecision;
use App\Enums\SalesBoardNonconformityOrigin;
use App\Enums\SalesBoardStaleImpact;
use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

/**
 * Recusas do fluxo de análise da Gestão.
 *
 * Todas são situações previsíveis do domínio -- a versão mudou, sobrou pendência
 * sem decisão, já existe quadro publicado para a competência -- e não defeitos.
 * Por isso não sobem para o log de erros: viram mensagem para quem está na tela,
 * dizendo o que fazer.
 */
class SalesBoardManagementReviewException extends RuntimeException implements ShouldntReport
{
    /**
     * Código de domínio do conflito com posição já registrada. Existe para que a
     * recusa seja identificável por quem chama sem depender do texto.
     */
    public const LEGACY_POSITION_EXISTS = 'LEGACY_POSITION_EXISTS';

    public static function cycleNotInManagement(SalesBoardCycleStatus $status): self
    {
        return new self(sprintf(
            'A competência não está em análise da Gestão: ela está em "%s".',
            $status->label(),
        ));
    }

    public static function withoutCurrentBaseline(): self
    {
        return new self('A competência não tem versão vigente para ser analisada.');
    }

    public static function withoutSubmittedBuilderReview(): self
    {
        return new self('Não há validação da construtora enviada para a versão vigente desta competência.');
    }

    public static function builderReviewNotApplicable(): self
    {
        return new self('A validação da construtora não se refere à versão vigente da posição. '
            .'Ela precisa ser refeita antes da análise.');
    }

    public static function reviewNotEditable(): self
    {
        return new self('Esta análise já foi encerrada e não pode mais ser alterada.');
    }

    public static function reviewSuperseded(): self
    {
        return new self('Esta análise foi substituída por uma nova versão da posição e é somente leitura.');
    }

    public static function baselineChanged(): self
    {
        return new self('Uma nova versão da posição foi gerada. '
            .'As decisões desta análise não se referem mais ao quadro vigente.');
    }

    public static function decisionNotAllowedForOrigin(
        SalesBoardNonconformityDecision $decision,
        SalesBoardNonconformityOrigin $origin,
    ): self {
        return new self(match ($origin) {
            SalesBoardNonconformityOrigin::BuilderDeclared => sprintf(
                'Uma declaração da construtora não pode terminar em "%s". '
                    .'Ou a declaração não procede, ou a fonte precisa ser corrigida.',
                $decision->label(),
            ),
            SalesBoardNonconformityOrigin::SystemSaleNonConform => sprintf(
                'Uma venda fora da política não pode terminar em "%s". '
                    .'Ou a exceção é aprovada, ou a fonte precisa ser corrigida.',
                $decision->label(),
            ),
            SalesBoardNonconformityOrigin::SystemSaleUndetermined => sprintf(
                'O Nimbus não determinou a conformidade desta venda, então ela não pode terminar em "%s". '
                    .'Uma exceção é concedida contra um limite, e é o limite que está faltando: '
                    .'a fonte precisa ser corrigida e a posição recalculada.',
                $decision->label(),
            ),
        });
    }

    public static function decisionReasonRequired(): self
    {
        return new self('Toda decisão exige um motivo com pelo menos 10 caracteres. '
            .'Descreva o que sustenta a conclusão -- é o que a auditoria vai ler.');
    }

    public static function decisionReasonNotAllowed(): self
    {
        return new self('Devolver uma pendência para "pendente" apaga a decisão anterior e não aceita motivo.');
    }

    public static function returnReasonRequired(): self
    {
        return new self('A devolução exige um motivo com pelo menos 10 caracteres. '
            .'Ele é o que a construtora vai ler ao abrir a nova rodada.');
    }

    /**
     * @param  list<string>  $items
     */
    public static function nonconformitiesPending(array $items): self
    {
        return new self(sprintf(
            'Existem %d não conformidade(s) sem decisão da Gestão: %s.',
            count($items),
            implode('; ', array_slice($items, 0, 5)).(count($items) > 5 ? '; …' : ''),
        ));
    }

    /**
     * @param  list<string>  $items
     */
    public static function correctionRequired(array $items): self
    {
        return new self(sprintf(
            'Existem não conformidades que exigem correção da fonte antes da aprovação: %s.',
            implode('; ', array_slice($items, 0, 5)).(count($items) > 5 ? '; …' : ''),
        ));
    }

    public static function staleBlocksApproval(SalesBoardStaleImpact $impact): self
    {
        return new self(match ($impact) {
            SalesBoardStaleImpact::Material => 'Os dados de origem alteraram materialmente a posição. '
                .'Recalcule antes da aprovação.',
            SalesBoardStaleImpact::Blocking => 'A fonte atual está incompleta e não sustenta a posição. '
                .'Resolva os dados pendentes antes da aprovação.',
            default => 'A situação da fonte não permite a aprovação.',
        });
    }

    public static function sourceChangeReasonRequired(): self
    {
        return new self('Os dados de origem foram alterados desde que esta versão foi congelada. '
            .'Informe a justificativa para aprovar sem recálculo material.');
    }

    public static function sourceChangeReasonNotAllowed(): self
    {
        return new self('A fonte não mudou desde que esta versão foi congelada. '
            .'Não há o que justificar.');
    }

    public static function declarationRequired(): self
    {
        return new self('É necessário confirmar a declaração de análise para aprovar e publicar.');
    }

    public static function baselineIncomplete(int $undeterminedUnits): self
    {
        return new self($undeterminedUnits > 0
            ? sprintf(
                'A posição tem %d unidade(s) sem classificação e não pode ser publicada.',
                $undeterminedUnits,
            )
            : 'A posição está incompleta -- algum valor não pôde ser apurado -- e não pode ser publicada.');
    }

    /**
     * A materialização das pendências de conformidade não bate com o que o
     * baseline congelou.
     *
     * Não deveria acontecer: a abertura da análise cria uma pendência para cada
     * venda decidível. Mas o custo de estar errado aqui é uma posição publicada
     * com uma venda que ninguém analisou, então a aprovação confere de novo em
     * vez de confiar na materialização.
     *
     * @param  list<string>  $contracts
     */
    public static function conformityWithoutNonconformity(array $contracts): self
    {
        return new self(sprintf(
            'A análise não cobre todas as vendas apontadas pela versão vigente: %s. '
                .'Reabra a análise da Gestão para materializar as pendências que faltam.',
            implode('; ', array_slice($contracts, 0, 5)).(count($contracts) > 5 ? '; …' : ''),
        ));
    }

    /**
     * O conflito que impede a publicação automática de destruir trabalho manual.
     */
    public static function legacyPositionExists(string $constructionName, string $referenceMonth): self
    {
        return new self(sprintf(
            '[%s] Já existe um Quadro de Vendas registrado para %s em %s. '
                .'A publicação automática não substitui posição existente.',
            self::LEGACY_POSITION_EXISTS,
            $constructionName,
            $referenceMonth,
        ));
    }

    public static function actorRequired(): self
    {
        return new self('Não foi possível identificar quem está conduzindo esta análise.');
    }

    public static function nonconformityOutsideReview(): self
    {
        return new self('A não conformidade não pertence à análise em andamento.');
    }
}
