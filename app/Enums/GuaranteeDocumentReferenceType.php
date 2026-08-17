<?php

namespace App\Enums;

/**
 * Papel que um documento exerce sobre uma garantia (§6 do escopo).
 */
enum GuaranteeDocumentReferenceType: string
{
    case Constitution = 'constitution';
    case Amendment = 'amendment';
    case Reinforcement = 'reinforcement';
    case Substitution = 'substitution';
    case Release = 'release';
    case Registration = 'registration';
    case Evidence = 'evidence';

    public function label(): string
    {
        return match ($this) {
            self::Constitution => 'Constituição inicial',
            self::Amendment => 'Alteração',
            self::Reinforcement => 'Reforço',
            self::Substitution => 'Substituição',
            self::Release => 'Liberação',
            self::Registration => 'Registro',
            self::Evidence => 'Comprovação',
        };
    }

    public function toEventType(): GuaranteeEventType
    {
        return match ($this) {
            self::Constitution => GuaranteeEventType::Constitution,
            self::Amendment => GuaranteeEventType::Amendment,
            self::Reinforcement => GuaranteeEventType::Reinforcement,
            self::Substitution => GuaranteeEventType::Substitution,
            self::Release => GuaranteeEventType::Release,
            self::Registration => GuaranteeEventType::Registration,
            self::Evidence => GuaranteeEventType::StatusChange,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $type) {
            $options[$type->value] = $type->label();
        }

        return $options;
    }
}
