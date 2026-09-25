<?php

namespace App\Exceptions;

use App\Domain\PuCalculator\Exceptions\PuMakerCheckerException;
use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

/**
 * Recusa da segregação maker/checker do Quadro de Vendas.
 *
 * Quem prepara um ato não o conclui: quem enviou a validação da construtora não
 * aprova a publicação daquela rodada, e quem abriu a homologação não a aprova nem
 * ativa a automação com base nela. Super admin é a exceção, como no módulo de PU
 * ({@see PuMakerCheckerException}).
 *
 * É situação prevista, não defeito: não sobe para o log de erros e vira mensagem
 * para quem está na tela, dizendo a quem pedir.
 */
class SalesBoardMakerCheckerException extends RuntimeException implements ShouldntReport
{
    public static function approverSubmittedBuilderReview(): self
    {
        return new self('A publicação exige segregação maker/checker: quem enviou a validação da construtora '
            .'desta rodada não pode aprová-la. Solicite a aprovação a outro usuário com a permissão de aprovação '
            .'do Quadro de Vendas (ou a um super admin).');
    }

    public static function approverOpenedHomologation(): self
    {
        return new self('A aprovação da homologação exige segregação maker/checker: quem abriu a homologação '
            .'não pode aprová-la. Solicite a aprovação a outro usuário com a permissão de aprovação do Quadro de '
            .'Vendas (ou a um super admin).');
    }

    public static function activatorOpenedHomologation(): self
    {
        return new self('A ativação exige segregação maker/checker: quem abriu a homologação não pode ativar a '
            .'automação com base nela. Solicite a ativação a outro usuário com a permissão de aprovação do Quadro '
            .'de Vendas (ou a um super admin).');
    }
}
