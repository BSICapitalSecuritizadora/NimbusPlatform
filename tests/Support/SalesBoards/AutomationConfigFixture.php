<?php

declare(strict_types=1);

namespace Tests\Support\SalesBoards;

use Closure;

/**
 * Avalia o arquivo de configuração do Quadro de Vendas com o ambiente trocado.
 *
 * É a única forma honesta de testar a leitura do ambiente: `config()->set()`
 * seguido de `config()` só prova que o repositório de configuração guarda o que
 * recebeu. Aqui o `config/sales_board.php` de verdade é reavaliado, com as
 * variáveis indicadas no lugar das reais, e o ambiente volta ao que era depois.
 */
final class AutomationConfigFixture
{
    /**
     * O arquivo de configuração avaliado com o ambiente indicado.
     *
     * @param  array<string, string|null>  $env
     * @return array<string, mixed>
     */
    public static function under(array $env): array
    {
        return self::withEnv($env, fn (): array => require base_path('config/sales_board.php'));
    }

    /**
     * Executa o callback com variáveis de ambiente trocadas, restaurando-as depois.
     *
     * @param  array<string, string|null>  $values
     */
    public static function withEnv(array $values, Closure $callback): mixed
    {
        $previous = [];

        foreach ($values as $key => $value) {
            $putenv = getenv($key);

            $previous[$key] = [
                'env' => array_key_exists($key, $_ENV) ? [$_ENV[$key]] : null,
                'server' => array_key_exists($key, $_SERVER) ? [$_SERVER[$key]] : null,
                'putenv' => $putenv === false ? null : [$putenv],
            ];

            if ($value === null) {
                unset($_ENV[$key], $_SERVER[$key]);
                putenv($key);
            } else {
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
                putenv($key.'='.$value);
            }
        }

        try {
            return $callback();
        } finally {
            foreach ($previous as $key => $state) {
                if ($state['env'] === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $state['env'][0];
                }

                if ($state['server'] === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $state['server'][0];
                }

                if ($state['putenv'] === null) {
                    putenv($key);
                } else {
                    putenv($key.'='.$state['putenv'][0]);
                }
            }
        }
    }
}
