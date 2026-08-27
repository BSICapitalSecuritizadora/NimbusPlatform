<?php

namespace App\Enums;

enum MeasurementResponsibility: string
{
    case EngineeringReviewer = 'engineering_reviewer';
    case ManagementReviewer = 'management_reviewer';
    case ComplianceReviewer = 'compliance_reviewer';
    case PaymentManager = 'payment_manager';
    case ReceiptUploader = 'receipt_uploader';
    case Finalizer = 'finalizer';

    public function label(): string
    {
        return match ($this) {
            self::EngineeringReviewer => 'Revisão de Engenharia',
            self::ManagementReviewer => 'Revisão de Gestão',
            self::ComplianceReviewer => 'Revisão de Compliance',
            self::PaymentManager => 'Gestor de Pagamento',
            self::ReceiptUploader => 'Uploader de Comprovante',
            self::Finalizer => 'Finalizador',
        };
    }

    public function stage(): int
    {
        return match ($this) {
            self::EngineeringReviewer => 1,
            self::ManagementReviewer => 2,
            self::ComplianceReviewer => 3,
            self::PaymentManager => 4,
            self::ReceiptUploader, self::Finalizer => 5,
        };
    }

    public function operationColumn(): string
    {
        return match ($this) {
            self::EngineeringReviewer => 'responsible_user_id',
            self::ManagementReviewer => 'stage2_reviewer_user_id',
            self::ComplianceReviewer => 'stage3_reviewer_user_id',
            self::PaymentManager => 'payment_manager_user_id',
            self::ReceiptUploader => 'payment_receipt_uploader_user_id',
            self::Finalizer => 'payment_finalizer_user_id',
        };
    }

    public function permission(): string
    {
        return match ($this) {
            self::EngineeringReviewer,
            self::ManagementReviewer,
            self::ComplianceReviewer => 'measurements.review',
            self::PaymentManager => 'measurements.pay',
            self::ReceiptUploader => 'measurements.receipts',
            self::Finalizer => 'measurements.finalize',
        };
    }

    /** @return array<string, string> */
    public static function options(?int $stage = null): array
    {
        return collect(self::cases())
            ->when($stage !== null, fn ($responsibilities) => $responsibilities->filter(
                fn (self $responsibility): bool => $responsibility->stage() === $stage,
            ))
            ->mapWithKeys(fn (self $responsibility): array => [
                $responsibility->value => $responsibility->label(),
            ])
            ->all();
    }

    /** @return list<self> */
    public static function forStage(int $stage): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $responsibility): bool => $responsibility->stage() === $stage,
        ));
    }

    public static function primaryForStage(int $stage): ?self
    {
        return match ($stage) {
            1 => self::EngineeringReviewer,
            2 => self::ManagementReviewer,
            3 => self::ComplianceReviewer,
            4 => self::PaymentManager,
            5 => self::Finalizer,
            default => null,
        };
    }

    public static function fromOperationColumn(string $column): ?self
    {
        return collect(self::cases())->first(
            fn (self $responsibility): bool => $responsibility->operationColumn() === $column,
        );
    }
}
