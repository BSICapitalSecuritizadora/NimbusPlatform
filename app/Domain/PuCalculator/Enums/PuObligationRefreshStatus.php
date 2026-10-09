<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

/**
 * Pedido durável de atualização das obrigações de uma emissão (Fase 6).
 *
 * Gravado na MESMA transação do fato que o provoca: se o fato comitou, o pedido
 * existe -- mesmo que o processo morra antes de executar a atualização. Quem
 * executa depois relê o estado governado vigente; o pedido só diz "esta emissão
 * precisa ser recomposta", nunca o que calcular.
 */
enum PuObligationRefreshStatus: string
{
    /** Gravado, ainda sem tentativa. */
    case Pending = 'pending';

    /** Uma execução o reservou (com prazo de concessão). */
    case Running = 'running';

    /** Falha passageira: nova tentativa agendada. */
    case RetryScheduled = 'retry_scheduled';

    /** Atendido pela execução que o reservou. */
    case Succeeded = 'succeeded';

    /** Atendido por uma execução posterior bem-sucedida da mesma emissão. */
    case Superseded = 'superseded';

    /** Tentativas automáticas esgotadas: só retomada autorizada. */
    case Exhausted = 'exhausted';

    /** Falha permanente (domínio/governança): não se repete sozinho. */
    case Blocked = 'blocked';

    /**
     * Ainda exige trabalho (automático ou manual).
     */
    public function isOpen(): bool
    {
        return ! in_array($this, [self::Succeeded, self::Superseded], true);
    }

    /**
     * A recuperação automática pode pegá-lo.
     */
    public function isAutomaticallyRecoverable(): bool
    {
        return in_array($this, [self::Pending, self::Running, self::RetryScheduled], true);
    }

    /** @return list<string> */
    public static function openValues(): array
    {
        return array_values(array_map(
            fn (self $status): string => $status->value,
            array_filter(self::cases(), fn (self $status): bool => $status->isOpen()),
        ));
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendente',
            self::Running => 'Em execução',
            self::RetryScheduled => 'Nova tentativa agendada',
            self::Succeeded => 'Concluída',
            self::Superseded => 'Atendida por execução posterior',
            self::Exhausted => 'Tentativas esgotadas',
            self::Blocked => 'Bloqueada (falha permanente)',
        };
    }
}
