<?php

declare(strict_types=1);

namespace App\Actions\ContractInstallments;

use App\Models\ImportRun;

/**
 * O que a confirmação de uma planilha de parcelas gravou.
 */
final readonly class ContractInstallmentImportResult
{
    /**
     * @param  array<string, int>  $warningsByCode  código do aviso => linhas com ele
     * @param  int  $cancellationSkipped  ausentes em aberto na conferência que receberam
     *                                    pagamento ou cancelamento durante o Confirmar e
     *                                    por isso não foram canceladas
     * @param  list<int>  $cancelledConstructionIds  obras das parcelas canceladas por ausência
     */
    public function __construct(
        public int $created,
        public int $updated,
        public int $unchanged,
        public int $cancelled,
        public int $contracts,
        public int $warned,
        public int $absent,
        public string $digest,
        public ImportRun $run,
        public array $warningsByCode = [],
        public int $cancellationSkipped = 0,
        public array $cancelledConstructionIds = [],
    ) {}
}
