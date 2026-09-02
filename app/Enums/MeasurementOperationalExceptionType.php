<?php

namespace App\Enums;

enum MeasurementOperationalExceptionType: string
{
    case MissingCurrentStageResponsible = 'missing_current_stage_responsible';
    case MissingPaymentManager = 'missing_payment_manager';
    case MissingReceiptUploader = 'missing_receipt_uploader';
    case MissingFinalizer = 'missing_finalizer';
    case SlaNotConfigured = 'sla_not_configured';
    case SlaInvalidConfig = 'sla_invalid_config';

    public function label(): string
    {
        return match ($this) {
            self::MissingCurrentStageResponsible => 'Responsável da etapa ausente',
            self::MissingPaymentManager => 'Gestor de pagamento ausente',
            self::MissingReceiptUploader => 'Uploader de comprovante ausente',
            self::MissingFinalizer => 'Finalizador ausente',
            self::SlaNotConfigured => 'SLA não configurado',
            self::SlaInvalidConfig => 'Configuração de SLA inválida',
        };
    }

    public function operationalMessage(?MeasurementResponsibility $responsibility = null): string
    {
        return match ($this) {
            self::MissingCurrentStageResponsible => sprintf(
                'A operação não possui responsável operacionalmente válido configurado para %s.',
                $responsibility?->label() ?? 'a etapa atual',
            ),
            self::MissingPaymentManager => 'A operação não possui gestor de pagamento operacionalmente válido configurado.',
            self::MissingReceiptUploader => 'A operação não possui uploader de comprovante operacionalmente válido configurado.',
            self::MissingFinalizer => 'A operação não possui finalizador operacionalmente válido configurado.',
            self::SlaNotConfigured => 'A etapa atual não possui um prazo de SLA configurado.',
            self::SlaInvalidConfig => 'A configuração de SLA da etapa atual precisa de revisão administrativa.',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::SlaNotConfigured, self::SlaInvalidConfig => 'warning',
            default => 'danger',
        };
    }

    public function isResponsibilityConfiguration(): bool
    {
        return match ($this) {
            self::MissingCurrentStageResponsible,
            self::MissingPaymentManager,
            self::MissingReceiptUploader,
            self::MissingFinalizer => true,
            default => false,
        };
    }

    public static function forResponsibility(MeasurementResponsibility $responsibility): self
    {
        return match ($responsibility) {
            MeasurementResponsibility::EngineeringReviewer,
            MeasurementResponsibility::ManagementReviewer,
            MeasurementResponsibility::ComplianceReviewer => self::MissingCurrentStageResponsible,
            MeasurementResponsibility::PaymentManager => self::MissingPaymentManager,
            MeasurementResponsibility::ReceiptUploader => self::MissingReceiptUploader,
            MeasurementResponsibility::Finalizer => self::MissingFinalizer,
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $type) {
            $options[$type->value] = $type->label();
        }

        return $options;
    }
}
