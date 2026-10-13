<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Support;

use Illuminate\Support\Facades\Config;

/**
 * A leitura segura do interruptor global das operações do PU (Fase 7).
 *
 * O interruptor é mecanismo de segurança, então tudo aqui falha fechado:
 * `(bool) env(...)` transforma qualquer texto não vazio em `true` -- `off`, `no`
 * e um erro de digitação ligariam a geração de curvas em silêncio. Só um valor
 * afirmativo inequívoco (`true`, `1`, `on`, `yes`) liga; ausente, vazio, negativo
 * ou ilegível é desligado.
 *
 * A interpretação acontece no arquivo de configuração (o `config:cache` guarda o
 * valor já interpretado) e de novo em runtime, por defesa em profundidade: um
 * `config()->set()` com texto chega aqui do mesmo jeito que a variável de
 * ambiente. Mesmo padrão de `SalesBoardAutomationConfig`.
 */
final class PuOperationsConfig
{
    public const ENABLED_KEY = 'pu_calculator.operations.enabled';

    public static function flag(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (! is_string($value)) {
            return false;
        }

        return filter_var(trim($value), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }

    /**
     * As operações do PU estão ligadas neste ambiente? Desligado quando nada
     * legível foi configurado.
     */
    public static function enabled(): bool
    {
        return self::flag(Config::get(self::ENABLED_KEY, false));
    }
}
