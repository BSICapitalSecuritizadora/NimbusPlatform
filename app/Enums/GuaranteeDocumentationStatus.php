<?php

namespace App\Enums;

/**
 * Quão amparada em documento está uma garantia (§14 do escopo).
 *
 * É estado derivado, não coluna: sai das referências documentais confirmadas e
 * das candidatas pendentes. Guardá-lo num campo criaria uma segunda verdade que
 * envelheceria a cada documento processado.
 *
 * A distinção que importa é entre a garantia digitada à mão — que existe no
 * cadastro mas ninguém sabe onde está prevista — e a que tem cláusula, página e
 * trecho apontando para o instrumento.
 */
enum GuaranteeDocumentationStatus: string
{
    case ManuallyRegistered = 'manually_registered';
    case DocumentationIdentified = 'documentation_identified';
    case DocumentedlyConfirmed = 'documentedly_confirmed';
    case DocumentaryConflict = 'documentary_conflict';

    public function label(): string
    {
        return match ($this) {
            self::ManuallyRegistered => 'Cadastrada manualmente — sem fonte documental',
            self::DocumentationIdentified => 'Documentação identificada, pendente de confirmação',
            self::DocumentedlyConfirmed => 'Confirmada documentalmente',
            self::DocumentaryConflict => 'Conflito documental',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::ManuallyRegistered => 'Sem fonte documental',
            self::DocumentationIdentified => 'Documentação identificada',
            self::DocumentedlyConfirmed => 'Confirmada documentalmente',
            self::DocumentaryConflict => 'Conflito documental',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::ManuallyRegistered => 'warning',
            self::DocumentationIdentified => 'info',
            self::DocumentedlyConfirmed => 'success',
            self::DocumentaryConflict => 'danger',
        };
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
