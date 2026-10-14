<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

/**
 * Situação operacional do PU de uma emissão (Fase 7).
 *
 * Só `activated` e `suspended` são gravados -- no livro só de inclusão
 * `emission_pu_operational_status_changes`, por uma decisão autorizada e
 * auditada. Os outros dois são derivados: sem parâmetros de PU a emissão não está
 * configurada; com parâmetros e sem decisão nenhuma ela está configurada e
 * inativa. Estar `active` no cadastro, ter parâmetros CDI ou ser encontrada pela
 * rotina diária não ativa nada.
 */
enum PuOperationalActivationStatus: string
{
    case NotConfigured = 'not_configured';
    case Inactive = 'inactive';
    case Activated = 'activated';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::NotConfigured => 'PU não configurado',
            self::Inactive => 'Configurado, operação do PU não ativada',
            self::Activated => 'Operação do PU ativada',
            self::Suspended => 'Operação do PU suspensa',
        };
    }

    /**
     * Só a emissão ativada entra no pipeline que grava curva, homologa e estende.
     */
    public function isOperational(): bool
    {
        return $this === self::Activated;
    }

    /**
     * A emissão já esteve no pipeline operacional: há (ou pode haver) fato
     * financeiro dela a registrar, mesmo com a geração suspensa.
     */
    public function wasEverActivated(): bool
    {
        return $this === self::Activated || $this === self::Suspended;
    }

    /**
     * Situações que uma decisão grava.
     *
     * @return list<self>
     */
    public static function recordable(): array
    {
        return [self::Activated, self::Suspended];
    }
}
