<?php

namespace App\Enums;

/**
 * O ciclo de vida de uma operação de obra.
 *
 * Quatro estados e uma tabela de transições -- não sete rótulos livres. `draft`
 * prepara, `active` opera, `completed` e `canceled` encerram. As capacidades
 * abaixo são a única fonte de verdade sobre o que cada estado permite: quem
 * precisar da resposta pergunta aqui, para que a regra não volte a ser um
 * `in_array()` copiado.
 */
enum OperationStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Completed = 'completed';
    case Canceled = 'canceled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Rascunho',
            self::Active => 'Em Andamento',
            self::Completed => 'Concluída',
            self::Canceled => 'Cancelada',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Active => 'success',
            self::Completed => 'info',
            self::Canceled => 'danger',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $status) {
            $options[$status->value] = $status->label();
        }

        return $options;
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Canceled => true,
            self::Draft, self::Active => false,
        };
    }

    /**
     * Só a operação plenamente operacional recebe medição nova.
     *
     * `draft` ainda está sendo configurada e os terminais já encerraram: nos
     * três casos, uma medição nova seria trabalho que ninguém pediu.
     */
    public function allowsNewMeasurements(): bool
    {
        return $this === self::Active;
    }

    /**
     * Trocar responsável é configurar quem trabalha; nos terminais não há mais
     * trabalho a atribuir. Os responsáveis já gravados permanecem -- o bloqueio
     * é sobre a escolha nova, nunca sobre o histórico.
     */
    public function allowsResponsibilityChanges(): bool
    {
        return match ($this) {
            self::Draft, self::Active => true,
            self::Completed, self::Canceled => false,
        };
    }

    /**
     * Delegação escopada empresta autoridade sobre trabalho em curso. Em `draft`
     * não há trabalho ainda, nos terminais não há mais.
     */
    public function allowsNewDelegations(): bool
    {
        return $this === self::Active;
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Active, self::Canceled],
            self::Active => [self::Completed, self::Canceled],
            self::Completed, self::Canceled => [self::Active],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Sair de um terminal só existe como reabertura administrativa: não há
     * caminho de terminal para terminal nem de terminal de volta a `draft`.
     */
    public function isReopeningTo(self $target): bool
    {
        return $this->isTerminal() && $target === self::Active;
    }
}
