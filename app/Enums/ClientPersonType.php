<?php

namespace App\Enums;

use App\Rules\Cnpj;
use App\Rules\Cpf;
use Illuminate\Contracts\Validation\ValidationRule;

enum ClientPersonType: string
{
    case Individual = 'pf';

    case Company = 'pj';

    public function label(): string
    {
        return match ($this) {
            self::Individual => 'Pessoa Física',
            self::Company => 'Pessoa Jurídica',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Individual => 'info',
            self::Company => 'warning',
        };
    }

    /**
     * Label of the name field, which holds the full name or the legal name.
     */
    public function nameLabel(): string
    {
        return match ($this) {
            self::Individual => 'Nome completo',
            self::Company => 'Razão Social',
        };
    }

    public function documentLabel(): string
    {
        return match ($this) {
            self::Individual => 'CPF',
            self::Company => 'CNPJ',
        };
    }

    public function documentLength(): int
    {
        return match ($this) {
            self::Individual => 11,
            self::Company => 14,
        };
    }

    public function documentMask(): string
    {
        return match ($this) {
            self::Individual => '999.999.999-99',
            self::Company => '99.999.999/9999-99',
        };
    }

    /**
     * Check-digit rule for the document of this person type.
     */
    public function documentRule(): ValidationRule
    {
        return match ($this) {
            self::Individual => new Cpf,
            self::Company => new Cnpj,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $type): array => [$type->value => $type->label()])
            ->all();
    }

    /**
     * Resolves the spellings accepted by the import spreadsheet.
     */
    public static function tryFromLabel(?string $value): ?self
    {
        $normalized = mb_strtolower(trim((string) $value));

        return match ($normalized) {
            'pf', 'f', 'fisica', 'física', 'pessoa fisica', 'pessoa física' => self::Individual,
            'pj', 'j', 'juridica', 'jurídica', 'pessoa juridica', 'pessoa jurídica' => self::Company,
            default => null,
        };
    }
}
