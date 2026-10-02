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
     * O SLA padrão dos lembretes, em dias civis corridos (e em falhas
     * consecutivas, no caso da falha técnica).
     *
     * Decidido no pacote de conclusão do Quadro, por delegação do dono:
     *
     * - **bloqueio no mesmo dia (0)**: o retry de um bloqueio é de 24 horas, e
     *   avisar no primeiro dia é o único jeito de o cadastro ser corrigido antes
     *   da tentativa seguinte -- com 1 dia a competência perderia um dia todo
     *   mês;
     * - **falha técnica a partir de 3 seguidas**: uma falha isolada costuma ser
     *   transitória e o backoff cuida dela;
     * - **pronta para a construtora no mesmo dia (0)**: a posição apurada não
     *   pode esperar ninguém lembrar de enviá-la;
     * - **validação 5/10 e análise 3/7** (lembrete/escalação): cabem no ciclo
     *   mensal, com a geração no dia 13 e a publicação até o fim do mês.
     *
     * A deduplicação diária por pessoa e canal continua valendo, e cada valor é
     * sobrescrevível por variável de ambiente.
     *
     * @var array<string, int>
     */
    public const DEFAULT_REMINDERS = [
        'blocked_after_days' => 0,
        'failed_after_attempts' => 3,
        'ready_for_builder_after_days' => 0,
        'builder_review_after_days' => 5,
        'builder_review_escalation_after_days' => 10,
        'management_review_after_days' => 3,
        'management_review_escalation_after_days' => 7,
    ];

    /**
     * O valor que desliga um lembrete pela variável de ambiente.
     */
    public const REMINDER_OFF = 'off';

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
     * Um limiar de lembrete lido do ambiente, com o padrão documentado.
     *
     * Omissão e valor inválido são coisas diferentes, e é essa a regra inteira:
     *
     * - **ausente**, vazio, só espaços ou o literal `null` (que o `env()` já
     *   entrega como `null`): ninguém escolheu nada, e vale o padrão;
     * - **`off`** (sem diferenciar caixa nem espaços) ou `false`: alguém
     *   desligou o lembrete de propósito;
     * - **inteiro não negativo**: o valor, com zero valendo "no mesmo dia";
     * - **qualquer outra coisa** -- texto, negativo, decimal, `true` --:
     *   desligado. Falha fechado: como bloqueio e "pronta para a construtora"
     *   têm padrão zero, devolver o padrão para um valor ilegível poderia
     *   transformar um erro de digitação em "avisar agora". Desligado, a tela
     *   "Automação do Quadro" mostra o item como desligado, e é por ela que o
     *   valor ilegível aparece.
     *
     * Lançar exceção aqui derrubaria o `config:cache` do startup -- e com ele o
     * site -- por um erro de digitação numa App Setting.
     */
    public static function reminderSetting(mixed $value, int $default): ?int
    {
        if ($value === null) {
            return $default;
        }

        if ($value === false) {
            return null;
        }

        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return $default;
        }

        if (strtolower($trimmed) === self::REMINDER_OFF) {
            return null;
        }

        $parsed = filter_var($trimmed, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

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
     * Só acontece quando alguém desligou os sete -- ou deixou os sete
     * ilegíveis --, porque cada um tem padrão. A tela precisa dizer isso:
     * responsáveis cadastrados sem nenhum aviso ligado dão a impressão de um
     * monitoramento que não existe.
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
