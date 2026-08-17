<?php

namespace App\Enums;

use App\Concerns\MoneyFormatter;
use Carbon\CarbonInterface;

/**
 * Como o valor de um campo é interpretado, comparado e exibido.
 *
 * A comparação entre versões usa o valor tipado (número/data), não o texto:
 * "R$ 30.000.000,00" e "30000000" são a mesma coisa e não devem virar uma
 * alteração falsa na tela de revisão.
 */
enum LegalInstrumentFieldValueType: string
{
    case Text = 'text';
    case Money = 'money';
    case Percentage = 'percentage';
    case Number = 'number';
    case Date = 'date';

    public function isNumeric(): bool
    {
        return $this === self::Money || $this === self::Percentage || $this === self::Number;
    }

    /**
     * O tipo do parâmetro é a interface, não a classe: a aplicação configura
     * `Date::use(CarbonImmutable::class)`, então todo cast de data em model
     * devolve `CarbonImmutable` — e exigir `Illuminate\Support\Carbon` aqui
     * quebraria em qualquer campo de data vindo do banco.
     */
    public function format(?string $value, ?float $numeric, ?CarbonInterface $date): string
    {
        return match ($this) {
            self::Money => $numeric === null
                ? ($value ?? 'Valor não localizado no documento.')
                : 'R$ '.MoneyFormatter::formatCurrencyForDisplay($numeric),
            self::Percentage => $numeric === null
                ? ($value ?? 'Valor não localizado no documento.')
                : rtrim(rtrim(number_format($numeric * 100, 2, ',', '.'), '0'), ',').'%',
            self::Number => $numeric === null
                ? ($value ?? 'Valor não localizado no documento.')
                : rtrim(rtrim(number_format($numeric, 4, ',', '.'), '0'), ','),
            self::Date => $date?->format('d/m/Y') ?? ($value ?? 'Valor não localizado no documento.'),
            self::Text => $value ?? 'Valor não localizado no documento.',
        };
    }

    /**
     * Percentuais aparecem nos contratos como "130%" e na digitação como "1,3".
     * Acima de 10 a leitura só faz sentido como percentual.
     */
    public function normalizeNumeric(?float $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if ($this === self::Percentage && $value > 10.0) {
            return $value / 100;
        }

        return $value;
    }
}
