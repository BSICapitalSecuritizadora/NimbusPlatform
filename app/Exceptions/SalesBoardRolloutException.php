<?php

namespace App\Exceptions;

use App\Enums\SalesBoardRolloutHomologationStatus;
use App\Enums\SalesBoardSource;
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

    public static function publishedBoardIsImmutable(): self
    {
        return new self('Este Quadro de Vendas foi publicado pela governança do ciclo mensal '
            .'e não pode ser alterado nem removido por fora dela. '
            .'Corrigir a posição significa corrigir a fonte e recalcular.');
    }

    public static function sourceChangedConcurrently(SalesBoardSource $current): self
    {
        return new self(sprintf(
            'O modo da Emissão mudou para "%s" enquanto esta ação estava aberta.',
            $current->label(),
        ));
    }
}
