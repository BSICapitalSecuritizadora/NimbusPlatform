<?php

declare(strict_types=1);

namespace App\Support\SalesBoards;

use Illuminate\Support\Facades\Config;

/**
 * A leitura segura da configuração da automação do Quadro de Vendas.
 *
 * O interruptor é mecanismo de segurança, e por isso tudo aqui falha fechado.
 * `(bool) env(...)` transforma qualquer texto não vazio em `true` -- `off`, `no`
 * e um erro de digitação ligariam a automação em silêncio --, e `(int) 'abc'`
 * vira `0`, que num limiar de lembrete significa "avisar agora". Configuração
 * que ninguém consegue ler nunca pode aumentar a atividade.
 *
 * A interpretação acontece no arquivo de configuração, e não nos serviços: o
 * `config:cache` guarda o valor já interpretado, e `env()` fora de `config/` para
 * de funcionar com o cache ligado. Os leitores em runtime passam pelos mesmos
 * métodos por defesa em profundidade -- um `config()->set()` com texto chega
 * aqui do mesmo jeito que uma variável de ambiente.
 */
final class SalesBoardAutomationConfig
{
    /**
     * Ligado só quando o valor diz inequivocamente que é: `true`, `1`, `yes`,
     * `on` (sem diferenciar caixa). Qualquer outra coisa -- ausente, vazio,
     * `off`, `2`, `banana` -- é desligado.
     */
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

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }

    /**
     * Um limiar em dias ou tentativas: inteiro não negativo, ou `null`.
     *
     * `null` desliga o aviso. Zero é valor legítimo -- "avisar assim que a
     * condição existir", como a Fase F já usa --, mas só quando escrito como
     * zero: texto, decimal ou negativo viram `null`, nunca `0`.
     */
    public static function threshold(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (! is_string($value)) {
            return null;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        return $parsed === false ? null : $parsed;
    }

    /**
     * O interruptor global, como os serviços devem lê-lo.
     */
    public static function enabled(): bool
    {
        return self::flag(Config::get('sales_board.automation.enabled'));
    }

    /**
     * Um limiar de lembrete, como os serviços devem lê-lo.
     */
    public static function reminderThreshold(string $key): ?int
    {
        return self::threshold(Config::get('sales_board.automation.reminders.'.$key));
    }
}
