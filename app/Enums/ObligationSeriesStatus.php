<?php

namespace App\Enums;

enum ObligationSeriesStatus: string
{
    case AwaitingConfiguration = 'awaiting_configuration';
    case Active = 'active';
    case Paused = 'paused';
    case Closed = 'closed';

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $status): array => [$status->value => $status->label()])
            ->all();
    }

    public function label(): string
    {
        return match ($this) {
            self::AwaitingConfiguration => 'Aguardando configuração',
            self::Active => 'Ativa',
            self::Paused => 'Pausada',
            self::Closed => 'Encerrada',
        };
    }
}
