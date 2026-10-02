<?php

namespace App\Enums;

/**
 * Por onde a resposta da construtora chegou à operação.
 *
 * O Nimbus ainda não tem acesso externo para a construtora: a posição vai a
 * ela pelo canal combinado, e quem registra a validação aqui dentro diz por
 * qual canal a resposta voltou. O canal entra na trilha junto com quem
 * respondeu e a data -- é o que permite à auditoria procurar a resposta
 * original fora do sistema.
 *
 * "Outro canal" existe para não forçar um rótulo errado, mas exige as
 * observações gerais da validação: sem elas, ninguém saberia qual canal foi.
 */
enum SalesBoardBuilderResponseChannel: string
{
    case Email = 'email';

    case MeetingMinutes = 'ata_reuniao';

    case Letter = 'oficio';

    case Other = 'outro';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'E-mail',
            self::MeetingMinutes => 'Reunião registrada em ata',
            self::Letter => 'Ofício ou carta',
            self::Other => 'Outro canal',
        };
    }

    /**
     * O canal precisa ser descrito nas observações gerais da validação?
     */
    public function requiresComment(): bool
    {
        return $this === self::Other;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
