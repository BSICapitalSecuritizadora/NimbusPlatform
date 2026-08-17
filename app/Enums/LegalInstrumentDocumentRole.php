<?php

namespace App\Enums;

/**
 * Papel de um documento dentro do dossiê do instrumento (§4 do escopo).
 *
 * É o papel — não a data — que diz se o documento estabelece a posição base ou
 * a altera. Sem isso, um laudo de avaliação anexado depois do 3º aditamento
 * seria lido como se alterasse o contrato.
 */
enum LegalInstrumentDocumentRole: string
{
    case Original = 'original';
    case Amendment = 'aditamento';
    case Accessory = 'instrumento_acessorio';
    case GuaranteeInstrument = 'instrumento_garantia';
    case GuaranteeReinforcement = 'reforco_garantia';
    case Release = 'liberacao';
    case Substitution = 'substituicao';
    case Registration = 'registro';
    case Annotation = 'averbacao';
    case Appraisal = 'avaliacao';
    case Discharge = 'quitacao';
    case SupportingDocument = 'documento_comprobatorio';
    case Other = 'outro';

    public function label(): string
    {
        return match ($this) {
            self::Original => 'Documento original',
            self::Amendment => 'Aditamento',
            self::Accessory => 'Instrumento acessório',
            self::GuaranteeInstrument => 'Instrumento de garantia',
            self::GuaranteeReinforcement => 'Reforço de garantia',
            self::Release => 'Liberação',
            self::Substitution => 'Substituição',
            self::Registration => 'Registro',
            self::Annotation => 'Averbação',
            self::Appraisal => 'Avaliação',
            self::Discharge => 'Termo de quitação',
            self::SupportingDocument => 'Documento comprobatório',
            self::Other => 'Outro',
        };
    }

    /**
     * O documento estabelece a posição inicial do instrumento?
     */
    public function isBase(): bool
    {
        return $this === self::Original;
    }

    /**
     * O documento pode alterar a posição vigente?
     *
     * Registros, averbações, laudos e comprovantes acrescentam prova, não
     * mudam a regra contratual — uma extração que proponha alteração a partir
     * deles vai para revisão marcada como conflito.
     */
    public function canAmendPosition(): bool
    {
        return match ($this) {
            self::Original,
            self::Amendment,
            self::Accessory,
            self::GuaranteeInstrument,
            self::GuaranteeReinforcement,
            self::Release,
            self::Substitution,
            self::Discharge => true,
            default => false,
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Original => 'primary',
            self::Amendment, self::Substitution => 'warning',
            self::Release, self::Discharge => 'gray',
            self::GuaranteeInstrument, self::GuaranteeReinforcement => 'success',
            default => 'info',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $role) {
            $options[$role->value] = $role->label();
        }

        return $options;
    }
}
