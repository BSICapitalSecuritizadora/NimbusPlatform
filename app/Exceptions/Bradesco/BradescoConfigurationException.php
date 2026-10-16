<?php

namespace App\Exceptions\Bradesco;

/**
 * Local configuration forbids the requested Bradesco operation.
 *
 * Raised before any HTTP traffic: live calls while disabled, unknown
 * authentication scheme, or missing base configuration.
 */
class BradescoConfigurationException extends BradescoException
{
    public static function liveDisabled(): self
    {
        return new self('Chamadas reais ao Bradesco estão desabilitadas. Use fixtures sintéticas ou solicite a ativação com autorização separada.');
    }

    public static function integrationDisabled(): self
    {
        return new self('A integração Bradesco está desabilitada neste ambiente.');
    }

    public static function authUnresolved(): self
    {
        return new self('O manual v2.2.2 não documenta autenticação (sem OAuth, chaves ou mTLS). Chamadas reais exigem o contrato de credenciais fornecido pelo banco.');
    }

    public static function missingConfirmation(): self
    {
        return new self('Chamadas reais exigem confirmação explícita do operador e nota de autorização.');
    }
}
