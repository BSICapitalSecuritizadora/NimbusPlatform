<?php

namespace App\Enums;

use App\Services\MeasurementPlanVersionResolver;

/**
 * Situação de uma versão do plano de medição (cronograma e Fundo de Obra de
 * uma obra dentro da operação).
 *
 * Quatro estados e uma tabela de transições, como o lifecycle da operação:
 * `draft` prepara uma versão, `active` é a vigente, `superseded` é a vigente
 * que outra substituiu e `cancelled` é o rascunho abandonado, que nunca
 * chegou a valer. Vigente e substituída são histórico: o conteúdo delas não
 * muda mais, e cada uma rege as competências da própria vigência -- a
 * substituída continua recebendo a medição atrasada delas
 * ({@see MeasurementPlanVersionResolver}).
 */
enum MeasurementPlanVersionStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Superseded = 'superseded';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Rascunho',
            self::Active => 'Vigente',
            self::Superseded => 'Substituída',
            self::Cancelled => 'Cancelada',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'warning',
            self::Active => 'success',
            self::Superseded => 'gray',
            self::Cancelled => 'danger',
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

    /**
     * Só o rascunho aceita mudança de conteúdo: cronograma, Fundo de Obra,
     * vigência e motivo.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /**
     * Vigente ou substituída: a versão valeu e pode estar ligada a medições.
     */
    public function hasBeenEffective(): bool
    {
        return match ($this) {
            self::Active, self::Superseded => true,
            self::Draft, self::Cancelled => false,
        };
    }

    /**
     * Rascunho vira vigente ou é cancelado; a vigente só deixa de valer quando
     * outra a substitui. Nada volta a rascunho e nada sai de substituída ou de
     * cancelada.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Active, self::Cancelled],
            self::Active => [self::Superseded],
            self::Superseded, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
