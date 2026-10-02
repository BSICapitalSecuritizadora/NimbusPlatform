<?php

namespace App\Exceptions;

use App\Enums\SalesBoardRolloutHomologationStatus;
use App\Enums\SalesBoardSource;
use App\Services\SalesBoards\SalesBoardPositionReader;
use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

/**
 * Recusas do rollout por Emissão.
 *
 * Todas são situações previsíveis -- escopo mudou, quadro manual conflita,
 * homologação envelheceu -- e não defeitos. Viram mensagem para quem está na
 * tela, dizendo o que fazer.
 */
class SalesBoardRolloutException extends RuntimeException implements ShouldntReport
{
    public static function emissionAlreadyAutomated(): self
    {
        return new self('Esta Emissão já está no modo automatizado.');
    }

    public static function emissionNotAutomated(): self
    {
        return new self('Esta Emissão não está no modo automatizado.');
    }

    public static function emissionInDraft(): self
    {
        return new self('A Emissão está em "Em Elaboração": o rollout do Quadro de Vendas só começa depois da elaboração, '
            .'quando a posição inicial deixa de ser composta. Conclua a elaboração antes de homologar ou ativar.');
    }

    public static function emissionLiquidated(): self
    {
        return new self('A Emissão está "Liquidada": a operação foi encerrada e não há competência mensal a automatizar.');
    }

    public static function homologationNotEditable(): self
    {
        return new self('Esta homologação já foi encerrada e não pode mais ser alterada.');
    }

    public static function homologationNotApproved(SalesBoardRolloutHomologationStatus $status): self
    {
        return new self(sprintf(
            'A ativação exige uma homologação aprovada; esta está em "%s".',
            $status->label(),
        ));
    }

    public static function homologationAlreadyUsed(): self
    {
        return new self('Esta homologação já foi usada numa ativação. '
            .'Reativar exige uma nova homologação, com os fatos revisados de novo.');
    }

    /**
     * "Conferir se ainda vale" só faz sentido para quem ainda pode sustentar
     * uma ativação: um rascunho se reavalia, e uma homologação rejeitada,
     * substituída ou já usada não volta a valer de jeito nenhum.
     */
    public static function homologationNotCheckable(): self
    {
        return new self('Só uma homologação aprovada e ainda não usada numa ativação pode ser conferida.');
    }

    public static function homologationDoesNotBelongToEmission(): self
    {
        return new self('A homologação não pertence a esta Emissão.');
    }

    public static function openHomologationExists(int $attempt): self
    {
        return new self(sprintf(
            'Já existe a homologação %d em andamento para esta Emissão. Conclua ou rejeite antes de abrir outra.',
            $attempt,
        ));
    }

    public static function withoutConstructions(): self
    {
        return new self('A Emissão não tem empreendimentos para homologar.');
    }

    /**
     * @param  list<string>  $constructions
     */
    public static function constructionsNotReady(array $constructions): self
    {
        return new self(sprintf(
            'A fonte de %d empreendimento(s) está incompleta e impede a homologação: %s. '
                .'Corrija o cadastro -- afrouxar a prontidão para o rollout passar apenas transferiria o problema para a primeira apuração.',
            count($constructions),
            implode('; ', array_slice($constructions, 0, 5)).(count($constructions) > 5 ? '; …' : ''),
        ));
    }

    /**
     * @param  list<string>  $constructions
     */
    public static function comparisonsNotAcknowledged(array $constructions): self
    {
        return new self(sprintf(
            'Há %d empreendimento(s) cuja comparação com o legado ainda não foi analisada: %s. '
                .'Uma diferença pode ser legítima, mas precisa ser entendida e registrada antes da homologação.',
            count($constructions),
            implode('; ', array_slice($constructions, 0, 5)).(count($constructions) > 5 ? '; …' : ''),
        ));
    }

    public static function guaranteesNotReviewed(): self
    {
        return new self('O impacto sobre as Garantias ainda não foi revisado.');
    }

    public static function monthlyReportNotReviewed(): self
    {
        return new self('O impacto sobre o Relatório Mensal ainda não foi revisado.');
    }

    public static function recipientsMissing(string $role): self
    {
        return new self(sprintf(
            'A Emissão precisa de pelo menos um %s ativo antes da ativação. '
                .'Sem destinatário, os avisos da automação não chegam a ninguém.',
            $role,
        ));
    }

    /**
     * @param  list<string>  $conflicts
     */
    public static function legacyBoardConflict(array $conflicts, string $startMonth): self
    {
        return new self(sprintf(
            'Já existe Quadro de Vendas registrado a partir da competência inicial (%s): %s. '
                .'A publicação automática seria recusada nessas competências. '
                .'Escolha uma competência inicial posterior -- apagar o registro manual não é caminho.',
            $startMonth,
            implode('; ', array_slice($conflicts, 0, 5)).(count($conflicts) > 5 ? '; …' : ''),
        ));
    }

    public static function assessmentStale(): self
    {
        return new self('A fonte ou a posição mudou desde a última revisão. '
            .'Reavalie a homologação antes de continuar.');
    }

    /**
     * A homologação aprovada deixou de descrever a fonte.
     *
     * Diferente de {@see self::assessmentStale()}, que fala com um rascunho: um
     * rascunho se reavalia; uma homologação aprovada não se reescreve.
     */
    public static function homologationStale(): self
    {
        return new self('A fonte ou a posição mudou desde a homologação aprovada. '
            .'Faça uma nova homologação: a ativação só pode usar exatamente o que a Gestão revisou.');
    }

    public static function scopeChanged(): self
    {
        return new self('Os empreendimentos da Emissão mudaram desde a homologação. '
            .'O rollout é por Emissão inteira, e um empreendimento novo não entra sem ser homologado.');
    }

    public static function reasonRequired(): self
    {
        return new self('Informe um motivo com pelo menos 10 caracteres. '
            .'É o que a auditoria vai ler.');
    }

    public static function actorRequired(): self
    {
        return new self('Não foi possível identificar quem está conduzindo esta ação.');
    }

    public static function startMonthRequired(): self
    {
        return new self('A competência inicial da automação é obrigatória. '
            .'Sem ela, a automação não teria a partir de quando começar -- e ausência de data não pode virar "desde sempre".');
    }

    public static function recipientNotOperational(): self
    {
        return new self('Apenas usuários ativos e aprovados podem receber os avisos da automação.');
    }

    /**
     * O guard que impede a escrita manual concorrer com a publicação.
     */
    public static function manualWriteBlocked(string $construction, string $referenceMonth): self
    {
        return new self(sprintf(
            'A Emissão está no modo automatizado a partir desta competência. '
                .'O Quadro de Vendas de %s em %s é produzido pelo ciclo mensal e publicado pela Gestão, '
                .'e registrá-lo à mão criaria uma segunda posição para o mesmo mês.',
            $construction,
            $referenceMonth,
        ));
    }

    /**
     * Não existe republicação: um ciclo aprovado não é recalculado, e dizer
     * "corrija a fonte e recalcule" mandaria o operador a um botão desabilitado.
     */
    public static function publishedBoardIsImmutable(): self
    {
        return new self('Este Quadro de Vendas foi publicado pela governança do ciclo mensal '
            .'e não pode ser alterado nem removido por fora dela. '
            .'A posição publicada permanece como foi aprovada: fatos lançados depois entram como movimentos extemporâneos '
            .'na competência seguinte, e a última competência publicada pode ser corrigida pela Gestão com “Retificar competência”.');
    }

    /**
     * O quadro sairia de baixo da Emissão do empreendimento.
     *
     * O {@see SalesBoardPositionReader} lê a posição por empreendimento: um
     * quadro gravado sob outra Emissão seria somado pela Emissão dele e pela do
     * empreendimento, as duas como se fosse delas.
     */
    public static function boardOutsideConstructionEmission(
        string $construction,
        string $referenceMonth,
        string $boardEmission,
        string $constructionEmission,
    ): self {
        return new self(sprintf(
            'O Quadro de Vendas de %s em %s seria gravado sob a Emissão %s, mas o empreendimento pertence à Emissão %s. '
                .'A posição é lida por empreendimento, e um quadro fora da Emissão dele seria somado nas duas.',
            $construction,
            $referenceMonth,
            $boardEmission,
            $constructionEmission,
        ));
    }

    /**
     * Já existe posição do empreendimento no mês, em qualquer Emissão.
     */
    public static function competenceAlreadyPositioned(string $construction, string $referenceMonth, string $existingEmission): self
    {
        return new self(sprintf(
            'O empreendimento %s já tem Quadro de Vendas em %s, registrado sob a Emissão %s. '
                .'Um segundo quadro para o mesmo mês faria a posição ser lida duas vezes; corrija o registro existente.',
            $construction,
            $referenceMonth,
            $existingEmission,
        ));
    }

    public static function sourceChangedConcurrently(SalesBoardSource $current): self
    {
        return new self(sprintf(
            'O modo da Emissão mudou para "%s" enquanto esta ação estava aberta.',
            $current->label(),
        ));
    }
}
