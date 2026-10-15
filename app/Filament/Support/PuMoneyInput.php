<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Support\PuCalculator\PuDisplay;
use Filament\Forms\Components\TextInput;
use Filament\Support\RawJs;

/**
 * Campo de valor em reais das telas financeiras do PU (Fase 8).
 *
 * Aceita o que a pessoa digita em pt-BR ("1.234,56") e entrega ao domínio o
 * decimal com ponto ("1234.56"). Nunca arredonda: três casas decimais é erro de
 * digitação, recusado com mensagem -- o valor liquidado é um fato, não uma conta.
 */
final class PuMoneyInput
{
    /** O mesmo formato que o livro de liquidações aceita. */
    public const PATTERN = '/^\d{1,13}(\.\d{1,2})?$/';

    public static function make(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->prefix('R$')
            ->inputMode('decimal')
            ->mask(RawJs::make(<<<'JS'
                $money($input, ',', '.', 2)
            JS))
            ->placeholder('0,00')
            ->mutateStateForValidationUsing(fn (mixed $state): mixed => self::parse($state) ?? $state)
            ->dehydrateStateUsing(fn (mixed $state): ?string => self::parse($state))
            ->rule('regex:'.self::PATTERN)
            ->validationMessages([
                'regex' => 'Informe o valor em reais, não negativo, com até duas casas decimais (ex.: 1.234,56).',
            ])
            ->extraInputAttributes(['class' => 'text-right tabular-nums']);
    }

    /**
     * "1.234,56" → "1234.56"; nulo para vazio ou para o que não é valor monetário
     * de até duas casas.
     */
    public static function parse(mixed $state): ?string
    {
        if ($state === null) {
            return null;
        }

        $value = trim(str_replace(['R$', ' ', "\u{00A0}"], '', (string) $state));

        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{1,3}(\.\d{3})*(,\d+)?$|^\d+(,\d+)?$/', $value) === 1) {
            $value = str_replace(['.', ','], ['', '.'], $value);
        }

        return preg_match(self::PATTERN, $value) === 1 ? $value : null;
    }

    /**
     * Valor gravado ("95.00") no formato do campo ("95,00").
     */
    public static function fill(?string $amount): ?string
    {
        return $amount !== null ? PuDisplay::decimal($amount, 2) : null;
    }
}
