<?php

namespace App\Enums;

use Illuminate\Support\Str;

enum VacancyDepartment: string
{
    case Comercial = 'Comercial';
    case Operacoes = 'Operações';
    case Riscos = 'Riscos';
    case Compliance = 'Compliance';
    case Juridico = 'Jurídico';
    case Tecnologia = 'Tecnologia';
    case Administrativo = 'Administrativo';
    case Financeiro = 'Financeiro';
    case Estruturacao = 'Estruturação';
    case RelacoesComInvestidores = 'Relações com Investidores';

    public function label(): string
    {
        return $this->value;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])
            ->all();
    }

    public static function labelFor(self|string|null $department): string
    {
        $resolved = self::fromValue($department);

        if ($resolved) {
            return $resolved->label();
        }

        if (blank($department)) {
            return 'Geral';
        }

        return Str::headline((string) $department);
    }

    public static function fromValue(self|string|null $department): ?self
    {
        if ($department instanceof self) {
            return $department;
        }

        if (blank($department)) {
            return null;
        }

        return self::tryFrom((string) $department);
    }

    /**
     * Normalize legacy/free-text department values (case-insensitive, accent-tolerant).
     */
    public static function normalize(?string $value): ?self
    {
        if (blank($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        foreach (self::cases() as $case) {
            if (strcasecmp($case->value, $trimmed) === 0) {
                return $case;
            }
        }

        return match (mb_strtolower($trimmed)) {
            'operacoes', 'operações' => self::Operacoes,
            'juridico', 'jurídico' => self::Juridico,
            'relacoes com investidores', 'relações com investidores', 'relacoes com invest.', 'ri' => self::RelacoesComInvestidores,
            'compliance e riscos', 'riscos e compliance' => self::Riscos,
            'administrativo e financeiro' => self::Administrativo,
            'tecnologia e dados', 'tecnologia', 'ti' => self::Tecnologia,
            'estruturacao e originacao', 'estruturação', 'estruturação e originação' => self::Estruturacao,
            default => null,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function filterOptions(): array
    {
        return self::options();
    }
}
