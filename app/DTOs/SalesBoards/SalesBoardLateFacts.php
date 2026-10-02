<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;
use App\Enums\SalesBoardMovementTiming;
use App\Models\Contract;
use App\Services\SalesBoards\SalesBoardDerivationService;

/**
 * Os fatos atrasados de um empreendimento: o que a fonte viva diz sobre a data
 * da competência anterior e que a posição congelada dela não refletiu.
 *
 * Intermediário da {@see SalesBoardDerivationService}, apurado antes da
 * resolução das políticas -- as vendas extemporâneas e as revisões entram na
 * mesma leitura de políticas que as vendas do mês -- e convertido em movimentos
 * com `timing` na composição da posição. Os contratos são os já carregados pela
 * derivação; nada aqui consulta o banco.
 */
readonly class SalesBoardLateFacts extends BaseDTO
{
    /**
     * @param  list<array{contract: Contract, timing: SalesBoardMovementTiming}>  $sales  vendas extemporâneas e revisões de venda publicada
     * @param  list<Contract>  $cancellations  distratos extemporâneos
     * @param  list<Contract>  $settlements  quitações extemporâneas
     * @param  list<SalesBoardIssue>  $issues  as reclassificações sem movimento que explique
     */
    public function __construct(
        public array $sales,
        public array $cancellations,
        public array $settlements,
        public array $issues,
    ) {}

    public static function none(): self
    {
        return new self([], [], [], []);
    }
}
