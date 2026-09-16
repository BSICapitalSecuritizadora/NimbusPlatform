<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;
use App\Services\SalesBoards\SalesBoardRolloutAssessmentService;

/**
 * O que a homologação enxergaria agora, calculado e não gravado.
 *
 * É o mesmo retrato que {@see SalesBoardRolloutAssessmentService::assess()}
 * persiste -- as mesmas linhas, o mesmo `assessment_hash`, o mesmo
 * `construction_scope_hash` --, com uma diferença só: ninguém o escreve. Existe
 * para que a ativação possa perguntar "o mundo ainda é o que a Gestão aprovou?"
 * sem reescrever a homologação aprovada para descobrir a resposta.
 *
 * As linhas não trazem os campos de aceite. Aceitar uma diferença é governança
 * sobre um retrato, e não fato do retrato: decidir se um aceite antigo continua
 * valendo é trabalho de quem persiste, comparando com a linha que já existe.
 */
readonly class SalesBoardRolloutObservation extends BaseDTO
{
    /**
     * @param  array<int, array<string, mixed>>  $constructionRows  atributos de cada empreendimento, por `construction_id`
     */
    public function __construct(
        public int $emissionId,
        public string $constructionScopeHash,
        public string $assessmentHash,
        public array $constructionRows,
    ) {}
}
