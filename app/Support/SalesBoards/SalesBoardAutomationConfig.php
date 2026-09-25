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
     * Por quanto tempo o scheduler segura o `withoutOverlapping()` da
     * automação.
     *
     * Um pouco acima da execução mais longa esperada -- uma recuperação de doze
     * competências de vinte empreendimentos leva cerca de dezesseis minutos --, e
     * muito abaixo do padrão de 24 horas do Laravel. O lock sobrevive a um
     * processo morto (fica no store de cache compartilhado), e com 24 horas um
     * deploy no meio de uma execução silenciava a automação por um dia inteiro.
     */
    public const OVERLAP_LOCK_MINUTES = 120;

    /**
     * O teto de memória da execução quando nada legível foi configurado.
     *
     * A geração de uma obra grande hidrata as parcelas da obra inteira; 128 MB
     * (o padrão do PHP sem `php.ini`) estoura a partir de umas 22 mil parcelas, e
     * 256 MB a partir de umas 86 mil.
     */
    public const DEFAULT_MEMORY_LIMIT = '512M';

    /**
     * Minutos até uma execução "executando" ser considerada interrompida.
     */
    public const DEFAULT_STALE_RUN_MINUTES = 180;

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
     * Um inteiro estritamente positivo, ou o default.
     *
     * Para cadências e prazos técnicos -- horas de retry, minutos de execução
     * travada --, e não para os limiares de lembrete. Aqui o valor ilegível não
     * desliga nada: ele volta ao default documentado. `(int) '24h'` seria 24 por
     * acaso, `(int) 'abc'` seria 0 e a política de retry faria `max(1, 0)` --
     * rederivar a obra bloqueada de hora em hora, exatamente o que a cadência de
     * 24 horas existe para impedir.
     */
    public static function positiveInteger(mixed $value, int $default): int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : $default;
        }

        if (! is_string($value)) {
            return $default;
        }

        $parsed = filter_var(trim($value), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $parsed === false ? $default : $parsed;
    }

    /**
     * Um `memory_limit` do PHP: `-1` ou um número com sufixo K, M ou G.
     *
     * Qualquer outra coisa volta ao default -- um teto ilegível não pode virar o
     * teto do `php.ini` da imagem, que é justamente o que estourava.
     */
    public static function memoryLimit(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            return self::DEFAULT_MEMORY_LIMIT;
        }

        $value = strtoupper(trim($value));

        return $value === '-1' || preg_match('/^[1-9]\d*[KMG]?$/', $value) === 1
            ? $value
            : self::DEFAULT_MEMORY_LIMIT;
    }

    /**
     * Um `memory_limit` em bytes; `null` quando é ilimitado.
     */
    public static function memoryLimitBytes(string $limit): ?int
    {
        $limit = strtoupper(trim($limit));

        if ($limit === '-1' || $limit === '') {
            return null;
        }

        $number = (int) $limit;

        return match (substr($limit, -1)) {
            'K' => $number * 1024,
            'M' => $number * 1024 * 1024,
            'G' => $number * 1024 * 1024 * 1024,
            default => $number,
        };
    }

    /**
     * Uma cadência de retry, como a política deve lê-la.
     */
    public static function retryHours(string $key, int $default): int
    {
        return self::positiveInteger(Config::get('sales_board.automation.retry.'.$key), $default);
    }

    /**
     * Minutos até uma execução "executando" ser dada como interrompida.
     *
     * Nunca abaixo do lock de sobreposição: uma execução viva ainda protegida
     * pelo `withoutOverlapping()` não pode ser encerrada por outra que entrou
     * pelo lado.
     */
    public static function staleRunMinutes(): int
    {
        return max(
            self::OVERLAP_LOCK_MINUTES,
            self::positiveInteger(Config::get('sales_board.automation.stale_run_after_minutes'), self::DEFAULT_STALE_RUN_MINUTES),
        );
    }

    /**
     * O teto de memória que a execução deve garantir para si mesma.
     */
    public static function runMemoryLimit(): string
    {
        return self::memoryLimit(Config::get('sales_board.automation.memory_limit'));
    }

    /**
     * Todos os lembretes de prazo estão desligados?
     *
     * É o estado padrão -- o projeto não tem SLA definido --, e a tela precisa
     * dizer isso: responsáveis cadastrados sem nenhum aviso ligado dão a
     * impressão de um monitoramento que não existe.
     */
    public static function allRemindersOff(): bool
    {
        foreach (array_keys((array) Config::get('sales_board.automation.reminders', [])) as $key) {
            if (self::reminderThreshold((string) $key) !== null) {
                return false;
            }
        }

        return true;
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
