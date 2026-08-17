<?php

namespace App\Enums;

/**
 * Enquadramento da operação numa competência (§25 do escopo).
 *
 * `PendingUpdate` e `InsufficientData` existem para impedir que a ausência de
 * um dado seja lida como desenquadramento — o cenário 7 do escopo.
 */
enum GuaranteeCoverageStatus: string
{
    case Compliant = 'compliant';
    case NearLimit = 'near_limit';
    case NonCompliant = 'non_compliant';
    case PendingUpdate = 'pending_update';
    case InsufficientData = 'insufficient_data';
    case NotApplicable = 'not_applicable';

    /** Margem sobre o mínimo contratual abaixo da qual a cobertura é "próxima do limite". */
    public const NEAR_LIMIT_MARGIN = 0.05;

    public function label(): string
    {
        return match ($this) {
            self::Compliant => 'Enquadrado',
            self::NearLimit => 'Próximo do limite',
            self::NonCompliant => 'Desenquadrado',
            self::PendingUpdate => 'Pendente de atualização',
            self::InsufficientData => 'Dados insuficientes',
            self::NotApplicable => 'Não aplicável',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Compliant => 'success',
            self::NearLimit => 'warning',
            self::NonCompliant => 'danger',
            self::PendingUpdate => 'warning',
            self::InsufficientData => 'gray',
            self::NotApplicable => 'gray',
        };
    }

    public function isBreach(): bool
    {
        return $this === self::NonCompliant;
    }

    /**
     * Deriva o enquadramento a partir da cobertura apurada e do mínimo exigido.
     *
     * A ordem importa: pendências vencem o cálculo. Uma operação a que falta o
     * valor de uma garantia não é "desenquadrada" — é "pendente", e tratá-la
     * como desenquadrada dispararia alerta indevido a cada fechamento.
     */
    public static function resolve(
        ?float $coverageRatio,
        ?float $requiredRatio,
        bool $hasPendingValues = false,
        bool $hasRequirement = true,
    ): self {
        if (! $hasRequirement) {
            return self::NotApplicable;
        }

        if ($hasPendingValues) {
            return self::PendingUpdate;
        }

        if ($coverageRatio === null || $requiredRatio === null) {
            return self::InsufficientData;
        }

        if ($coverageRatio < $requiredRatio) {
            return self::NonCompliant;
        }

        if ($coverageRatio < ($requiredRatio * (1 + self::NEAR_LIMIT_MARGIN))) {
            return self::NearLimit;
        }

        return self::Compliant;
    }
}
