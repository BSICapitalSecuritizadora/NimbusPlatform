<?php

namespace App\Support\ActivityLog;

use App\Exceptions\AuditRetentionConfigurationException;
use Illuminate\Support\Facades\Config;

/**
 * Os prazos de retenção da trilha de auditoria, lidos com rigor antes de
 * qualquer exclusão.
 *
 * O prazo é a única coisa entre `audit:clean-filtered` e sete anos de
 * evidência regulada, e por isso nunca é adivinhado. A leitura antiga,
 * `(int) env(...)`, transformava `''`, `'null'` e `'abc'` em 0 -- e um prazo
 * de zero dia apagava às 04:00 toda a trilha protegida --, `'-1'` punha o
 * corte no futuro e apagava até a trilha do dia, `'2.555'` virava 2 dias e
 * `'7 years'` virava 7. Aqui só passa um número inteiro de dias, escrito só
 * com algarismos (sem sinal, ponto, vírgula, unidade, espaço ou zero à
 * esquerda), dentro dos limites abaixo, com a retenção protegida nunca menor
 * que a descartável. Qualquer outro valor recusa os dois prazos de uma vez: a
 * limpeza não roda em parte.
 *
 * `config/audit.php` guarda o valor como veio, sem interpretar: a aplicação e
 * o `config:cache` continuam funcionando com um valor inválido, o cache guarda
 * o valor que foi recusado, e só quem apaga -- a limpeza -- recusa. Quem lê o
 * prazo para decidir alguma exclusão passa por aqui.
 */
final readonly class AuditRetentionPolicy
{
    /**
     * O menor prazo aprovado, para as duas categorias: um ano.
     */
    public const MINIMUM_DAYS = 365;

    /**
     * Cem anos. Não é política de retenção: é o limite técnico de um valor
     * legível. Acima dele já não há prazo, há erro de digitação -- e a data de
     * corte sai do intervalo que o Carbon representa (`subDays(PHP_INT_MAX)`
     * cai no dia seguinte e apagaria tudo).
     */
    public const MAXIMUM_DAYS = 36500;

    private function __construct(
        public int $disposableDays,
        public int $protectedDays,
    ) {}

    /**
     * Os prazos da configuração em vigor -- inclusive a guardada pelo
     * `config:cache`.
     *
     * @throws AuditRetentionConfigurationException
     */
    public static function fromConfig(): self
    {
        return self::fromValues(
            Config::get('audit.retention_disposable_days'),
            Config::get('audit.retention_workflow_days'),
        );
    }

    /**
     * @throws AuditRetentionConfigurationException
     */
    public static function fromValues(mixed $disposable, mixed $protected): self
    {
        $problems = [];
        $disposableDays = self::days($disposable, 'audit.retention_disposable_days', 'AUDIT_RETENTION_DISPOSABLE_DAYS', $problems);
        $protectedDays = self::days($protected, 'audit.retention_workflow_days', 'AUDIT_RETENTION_WORKFLOW_DAYS', $problems);

        if ($disposableDays !== null && $protectedDays !== null && $protectedDays < $disposableDays) {
            $problems[] = sprintf(
                'A retenção protegida (%d dias) é menor que a descartável (%d dias): a evidência regulada não pode expirar antes do registro genérico.',
                $protectedDays,
                $disposableDays,
            );
        }

        if ($disposableDays === null || $protectedDays === null || $problems !== []) {
            throw new AuditRetentionConfigurationException($problems);
        }

        return new self($disposableDays, $protectedDays);
    }

    /**
     * @param  list<string>  $problems
     */
    private static function days(mixed $value, string $key, string $variable, array &$problems): ?int
    {
        $days = match (true) {
            is_int($value) => $value,
            is_string($value) && preg_match('/^(0|[1-9][0-9]{0,8})$/', $value) === 1 => (int) $value,
            default => null,
        };

        if ($days === null) {
            $problems[] = sprintf(
                '%s (%s) = %s: informe um número inteiro de dias, só com algarismos -- sem sinal, ponto, vírgula, unidade, espaço ou zero à esquerda (por exemplo, 2555).',
                $key,
                $variable,
                self::describe($value),
            );

            return null;
        }

        if ($days < self::MINIMUM_DAYS || $days > self::MAXIMUM_DAYS) {
            $problems[] = sprintf(
                '%s (%s) = %d dias: o prazo precisa ficar entre %d e %d dias.',
                $key,
                $variable,
                $days,
                self::MINIMUM_DAYS,
                self::MAXIMUM_DAYS,
            );

            return null;
        }

        return $days;
    }

    private static function describe(mixed $value): string
    {
        return match (true) {
            $value === null => 'nulo',
            is_bool($value) => $value ? 'true' : 'false',
            $value === '' => 'vazio',
            is_string($value) => '"'.$value.'"',
            is_scalar($value) => var_export($value, true),
            default => get_debug_type($value),
        };
    }
}
