<?php

namespace App\Enums;

/**
 * Estado do processamento de um documento do dossiê (§5 do escopo).
 */
enum LegalInstrumentDocumentStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Processed = 'processed';
    case NeedsReview = 'needs_review';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Aguardando processamento',
            self::Processing => 'Processando',
            self::Processed => 'Processado',
            self::NeedsReview => 'Necessita revisão',
            self::Failed => 'Falhou',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Processed => 'success',
            self::Processing, self::Pending => 'info',
            self::NeedsReview => 'warning',
            self::Failed => 'danger',
        };
    }

    public function isActive(): bool
    {
        return $this === self::Pending || $this === self::Processing;
    }

    public function canRetry(): bool
    {
        return $this === self::Failed;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $status) {
            $options[$status->value] = $status->label();
        }

        return $options;
    }
}
