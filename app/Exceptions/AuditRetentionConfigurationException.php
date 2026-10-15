<?php

namespace App\Exceptions;

use App\Support\ActivityLog\AuditRetentionPolicy;
use RuntimeException;

/**
 * A retenção configurada da trilha de auditoria não pode ser lida com
 * segurança ({@see AuditRetentionPolicy}).
 *
 * Leva todos os problemas de uma vez, e não só o primeiro: quem corrige a
 * variável de ambiente precisa ver, numa única execução, tudo o que impede a
 * limpeza de rodar.
 */
class AuditRetentionConfigurationException extends RuntimeException
{
    /**
     * @param  list<string>  $problems
     */
    public function __construct(public readonly array $problems)
    {
        parent::__construct('Retenção da auditoria inválida: '.implode(' ', $problems));
    }
}
