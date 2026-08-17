<?php

namespace App\Enums;

/**
 * Situação jurídica da garantia (§9 do escopo). Distinta do enquadramento
 * financeiro: uma garantia pode estar juridicamente ativa e ainda assim deixar
 * a operação desenquadrada.
 */
enum GuaranteeLegalStatus: string
{
    case Active = 'active';
    case PendingConstitution = 'pending_constitution';
    case PendingRegistration = 'pending_registration';
    case PendingConfirmation = 'pending_confirmation';
    case PartiallyConstituted = 'partially_constituted';
    case Substituted = 'substituted';
    case Released = 'released';
    case Terminated = 'terminated';
    case Suspended = 'suspended';
    case Inconsistent = 'inconsistent';
    case NotDocumented = 'not_documented';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Ativa',
            self::PendingConstitution => 'Pendente de constituição',
            self::PendingRegistration => 'Pendente de registro',
            self::PendingConfirmation => 'Pendente de confirmação',
            self::PartiallyConstituted => 'Parcialmente constituída',
            self::Substituted => 'Substituída',
            self::Released => 'Liberada',
            self::Terminated => 'Encerrada',
            self::Suspended => 'Suspensa',
            self::Inconsistent => 'Inconsistente',
            self::NotDocumented => 'Não identificada documentalmente',
        };
    }

    /**
     * Estados em que a garantia ainda compõe a cobertura da operação.
     *
     * Parcialmente constituída conta: o valor elegível já reflete a parcela
     * efetivamente constituída, e zerá-la puniria a operação duas vezes.
     */
    public function countsTowardCoverage(): bool
    {
        return match ($this) {
            self::Active,
            self::PartiallyConstituted,
            self::PendingRegistration => true,
            default => false,
        };
    }

    public function isClosed(): bool
    {
        return match ($this) {
            self::Substituted,
            self::Released,
            self::Terminated => true,
            default => false,
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::PartiallyConstituted, self::PendingRegistration => 'info',
            self::PendingConstitution, self::PendingConfirmation, self::Suspended => 'warning',
            self::Inconsistent, self::NotDocumented => 'danger',
            self::Substituted, self::Released, self::Terminated => 'gray',
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
}
