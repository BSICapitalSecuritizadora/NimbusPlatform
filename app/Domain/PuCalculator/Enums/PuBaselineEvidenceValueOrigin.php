<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

/**
 * De onde veio o valor final gravado na evidência.
 *
 * Decidido no servidor a partir da extração guardada, nunca do que o
 * formulário afirma: o revisor precisa saber se está conferindo uma leitura da
 * IA, uma correção humana sobre ela ou um valor digitado sem apoio da análise.
 */
enum PuBaselineEvidenceValueOrigin: string
{
    case AiExtracted = 'ai_extracted';
    case AiExtractedEdited = 'ai_extracted_edited';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::AiExtracted => 'Extraído por IA',
            self::AiExtractedEdited => 'Extraído por IA e editado',
            self::Manual => 'Preenchido manualmente',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::AiExtracted => 'info',
            self::AiExtractedEdited => 'warning',
            self::Manual => 'gray',
        };
    }
}
