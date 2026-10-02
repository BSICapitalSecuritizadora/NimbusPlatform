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

    /**
     * Código de domínio do empreendimento que deixou a Emissão do ciclo.
     */
    public const CONSTRUCTION_OUTSIDE_CYCLE_EMISSION = 'CONSTRUCTION_OUTSIDE_CYCLE_EMISSION';

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
            SalesBoardNonconformityOrigin::SystemLateSaleWithoutPolicy => sprintf(
                'Uma venda de competência publicada sem política aplicável não pode terminar em "%s". '
                    .'Ou a exceção é aprovada, ou o valor ou a data da venda precisam ser corrigidos.',
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
     * A orientação é devolver, e não reabrir: reabrir devolve a mesma análise,
     * com as mesmas pendências. É a rodada seguinte -- nova validação da
     * construtora e nova análise -- que materializa tudo o que a versão vigente
     * aponta.
     *
     * @param  list<string>  $contracts
     */
    public static function conformityWithoutNonconformity(array $contracts): self
    {
        return new self(sprintf(
            'A análise não cobre todas as vendas apontadas pela versão vigente: %s. '
                .'Devolva a competência à construtora: a próxima análise da Gestão nasce com uma pendência '
                .'para cada venda que exige decisão.',
            implode('; ', array_slice($contracts, 0, 5)).(count($contracts) > 5 ? '; …' : ''),
        ));
    }

    /**
     * Uma divergência declarada pela construtora ficou sem pendência.
     *
     * Mesma defesa da conformidade, do lado da declaração: uma divergência que
     * não virou pendência nunca foi analisada pela Gestão.
     *
     * @param  list<string>  $divergences
     */
    public static function divergenceWithoutNonconformity(array $divergences): self
    {
        return new self(sprintf(
            'A análise não cobre todas as divergências declaradas pela construtora: %s. '
                .'Devolva a competência à construtora: a próxima análise da Gestão nasce com uma pendência '
                .'para cada divergência declarada.',
            implode('; ', array_slice($divergences, 0, 5)).(count($divergences) > 5 ? '; …' : ''),
        ));
    }

    /**
     * A competência deixou de ser da automação -- ou nunca foi.
     *
     * Publicar ali criaria um quadro imutável sem homologação ao lado. O ciclo
     * não tem mais como terminar em publicação, e o caminho é encerrá-lo.
     */
    public static function competenceNotCoveredByAutomation(string $emissionName, string $referenceMonth): self
    {
        return new self(sprintf(
            'A Emissão %s não cobre %s pela automação do Quadro de Vendas: ela voltou ao registro manual '
                .'ou a competência é anterior ao início da automação. Esta competência não pode ser publicada '
                .'pelo ciclo; use "Cancelar competência", na tela da competência, para encerrá-la.',
            $emissionName,
            $referenceMonth,
        ));
    }

    /**
     * A abertura encontrou a competência devolvida à construtora por uma versão
     * material nova -- a substituição que o recálculo não concluiu foi
     * concluída agora.
     */
    public static function returnedToBuilderByNewVersion(): self
    {
        return new self('A posição vigente mudou depois do envio da construtora, e a validação enviada foi '
            .'substituída. A competência voltou para a construtora: abra uma nova validação.');
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

    /**
     * O empreendimento pertence hoje a outra Emissão.
     *
     * Publicar sob a Emissão do ciclo gravaria um quadro fora da Emissão do
     * empreendimento, e o leitor da posição o somaria nas duas. O guard de
     * escrita também recusaria, mas com uma mensagem de registro manual que não
     * diz nada a quem está aprovando.
     */
    public static function constructionOutsideCycleEmission(string $constructionName, string $referenceMonth): self
    {
        return new self(sprintf(
            '[%s] O empreendimento %s pertence hoje a outra Emissão; a posição de %s não pode ser publicada sob a Emissão do ciclo. '
                .'Corrija o vínculo antes de aprovar.',
            self::CONSTRUCTION_OUTSIDE_CYCLE_EMISSION,
            $constructionName,
            $referenceMonth,
        ));
    }

    public static function actorRequired(): self
    {
        return new self('Não foi possível identificar quem está conduzindo esta análise.');
    }

    /**
     * A regra de ordem: os movimentos de M partem da posição da âncora -- M-1
     * ou, com M-1 cancelada, a primeira competência não cancelada antes dela --,
     * então M só é publicada depois que a âncora estiver aprovada ou cancelada.
     *
     * Com competências canceladas no meio, a mensagem diz por que a âncora não
     * é M-1: quem lê "aprove 06/2026" para liberar 08/2026 precisa saber que
     * 07/2026 foi cancelada.
     *
     * @param  list<string>  $cancelledMonths  `m/Y`, as canceladas entre a âncora e a competência
     */
    public static function priorCompetenceOpen(string $previousMonth, string $situation, string $month, array $cancelledMonths = []): self
    {
        if ($cancelledMonths === []) {
            return new self(sprintf(
                'A competência anterior (%s) ainda não foi aprovada nem cancelada (situação: %s). '
                    .'Os movimentos desta competência partem da posição da anterior: aprove ou cancele %s antes de aprovar %s.',
                $previousMonth,
                $situation,
                $previousMonth,
                $month,
            ));
        }

        return new self(sprintf(
            'A competência %s ainda não foi aprovada nem cancelada (situação: %s). '
                .'Como %s, os movimentos de %s partem da posição de %s: aprove ou cancele %s antes de aprovar %s.',
            $previousMonth,
            $situation,
            self::cancelledPhrase($cancelledMonths),
            $month,
            $previousMonth,
            $previousMonth,
            $month,
        ));
    }

    /**
     * @param  list<string>  $cancelledMonths  `m/Y`, as canceladas entre a âncora e a competência
     */
    public static function priorCompetenceUnderRectification(string $previousMonth, string $month, array $cancelledMonths = []): self
    {
        if ($cancelledMonths === []) {
            return new self(sprintf(
                'A competência anterior (%s) está em retificação. Conclua ou desista da retificação antes de aprovar %s.',
                $previousMonth,
                $month,
            ));
        }

        return new self(sprintf(
            'A competência %s está em retificação. Como %s, os movimentos de %s partem da posição de %s: '
                .'conclua ou desista da retificação antes de aprovar %s.',
            $previousMonth,
            self::cancelledPhrase($cancelledMonths),
            $month,
            $previousMonth,
            $month,
        ));
    }

    /**
     * Publicar uma competência anterior à última publicada daria aos fatos
     * dela um segundo dono: a posterior já reflete a posição deles, e os que
     * chegaram depois dela entram como extemporâneos na competência seguinte. A
     * saída é a mesma da reabertura recusada: cancelar esta competência.
     */
    public static function laterCompetencePublished(string $month, string $laterMonth): self
    {
        return new self(sprintf(
            'A competência %s não pode ser publicada: %s já foi publicada e a posição dela já reflete os fatos de %s. '
                .'Os fatos de %s lançados depois entram como extemporâneos na próxima competência a ser publicada. '
                .'Use "Cancelar competência" para encerrar %s.',
            $month,
            $laterMonth,
            $month,
            $month,
            $month,
        ));
    }

    /**
     * "07/2026 foi cancelada" ou "07/2026 e 08/2026 foram canceladas", do mês
     * mais antigo para o mais recente.
     *
     * @param  list<string>  $cancelledMonths  do mais recente para o mais antigo
     */
    private static function cancelledPhrase(array $cancelledMonths): string
    {
        $months = array_reverse($cancelledMonths);

        if (count($months) === 1) {
            return sprintf('%s foi cancelada', $months[0]);
        }

        $last = array_pop($months);

        return sprintf('%s e %s foram canceladas', implode(', ', $months), $last);
    }

    public static function nonconformityOutsideReview(): self
    {
        return new self('A não conformidade não pertence à análise em andamento.');
    }
}
