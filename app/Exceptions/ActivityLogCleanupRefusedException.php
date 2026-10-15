<?php

namespace App\Exceptions;

use App\Support\ActivityLog\RefuseGenericActivityLogCleanup;
use RuntimeException;

/**
 * A limpeza genérica da trilha de auditoria foi recusada antes de apagar
 * qualquer registro ({@see RefuseGenericActivityLogCleanup}).
 *
 * O que foi pedido vai para o log junto com a recusa: uma tentativa de apagar
 * a trilha protegida é, ela mesma, um fato a guardar.
 */
class ActivityLogCleanupRefusedException extends RuntimeException
{
    public const MESSAGE = 'activitylog:clean está desativado nesta aplicação: ele apaga a trilha de auditoria só pela idade, sem a classificação protegida de config/audit.php (Medição, PU e demais evidências reguladas) e sem a retenção protegida. Nenhum registro foi apagado. A limpeza da auditoria é a do comando audit:clean-filtered (use --dry-run para conferir antes).';

    public function __construct(
        public readonly ?int $maxAgeInDays,
        public readonly ?string $logName,
    ) {
        parent::__construct(self::MESSAGE);
    }

    /**
     * @return array{max_age_in_days: int|null, log_name: string|null}
     */
    public function context(): array
    {
        return [
            'max_age_in_days' => $this->maxAgeInDays,
            'log_name' => $this->logName,
        ];
    }
}
