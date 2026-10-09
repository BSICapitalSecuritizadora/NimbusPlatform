<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

/**
 * Por que uma operação do PU falhou, e se tentar de novo pode resolver (Fase 6).
 *
 * Só falha técnica passageira volta sozinha. Regra de domínio, governança,
 * insumo inválido, efeito sem regra e indexador sem homologação não se resolvem
 * repetindo: ficam bloqueados até alguém decidir.
 */
enum PuOperationalFailureCategory: string
{
    /** Deadlock, espera de trava, conexão perdida. */
    case TransientDatabase = 'transient_database';

    /** Trava de aplicação (cache lock) ocupada. */
    case TransientLock = 'transient_lock';

    /** Corrida que outra execução ganhou (chave única); a repetição converge. */
    case TransientConcurrency = 'transient_concurrency';

    /** A tentativa começou e não terminou (processo/worker interrompido). */
    case Interrupted = 'interrupted';

    /** Fonte externa fora do ar, timeout, HTTP 5xx/429. */
    case ProviderUnavailable = 'provider_unavailable';

    /** Fonte externa recusou a configuração (série, autenticação, HTTP 4xx). */
    case ProviderConfiguration = 'provider_configuration';

    /** Resposta da fonte externa fora do formato esperado. */
    case MalformedResponse = 'malformed_response';

    /** A engine recusou o domínio numérico (convergência, base de taxa). */
    case Numerical = 'numerical';

    /** Insumo contratual ou pré-requisito inválido. */
    case InvalidInputs = 'invalid_inputs';

    /** Recusa de governança (status, maker/checker, versão). */
    case Governance = 'governance';

    /** Efeito financeiro ou indexador sem regra/homologação operacional. */
    case Unsupported = 'unsupported';

    /** Invariante do domínio violado (imutabilidade, estado impossível). */
    case Invariant = 'invariant';

    case Unknown = 'unknown';

    /**
     * Repetir automaticamente pode resolver. Desconhecido conta como passageiro,
     * mas só dentro do limite de tentativas.
     */
    public function isRetryable(): bool
    {
        return in_array($this, [
            self::TransientDatabase,
            self::TransientLock,
            self::TransientConcurrency,
            self::Interrupted,
            self::ProviderUnavailable,
            self::Unknown,
        ], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::TransientDatabase => 'Falha passageira do banco de dados',
            self::TransientLock => 'Trava ocupada',
            self::TransientConcurrency => 'Corrida concorrente',
            self::Interrupted => 'Execução interrompida',
            self::ProviderUnavailable => 'Fonte externa indisponível',
            self::ProviderConfiguration => 'Fonte externa recusou a configuração',
            self::MalformedResponse => 'Resposta da fonte fora do formato',
            self::Numerical => 'Recusa numérica da engine',
            self::InvalidInputs => 'Insumo ou pré-requisito inválido',
            self::Governance => 'Recusa de governança',
            self::Unsupported => 'Sem regra ou homologação operacional',
            self::Invariant => 'Invariante do domínio violado',
            self::Unknown => 'Falha não classificada',
        };
    }
}
