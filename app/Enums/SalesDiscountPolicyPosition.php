<?php

namespace App\Enums;

/**
 * Onde uma política de desconto está, hoje, na linha do tempo da obra.
 *
 * `Current` é a que o resolvedor devolve para hoje. `Superseded` é a que uma
 * política mais nova encerrou antes do fim registrado -- inclusive a corrigida
 * por outra com o mesmo início, que nunca chegou a valer. `Ended` terminou no
 * próprio fim, sem substituição. `Scheduled` ainda não começou.
 */
enum SalesDiscountPolicyPosition: string
{
    case Current = 'current';

    case Scheduled = 'scheduled';

    case Superseded = 'superseded';

    case Ended = 'ended';

    public function label(): string
    {
        return match ($this) {
            self::Current => 'Vigente',
            self::Scheduled => 'Programada',
            self::Superseded => 'Substituída',
            self::Ended => 'Encerrada',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Current => 'success',
            self::Scheduled => 'info',
            self::Superseded => 'warning',
            self::Ended => 'gray',
        };
    }
}
