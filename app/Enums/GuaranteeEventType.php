<?php

namespace App\Enums;

/**
 * Eventos jurídicos que compõem o histórico de uma garantia (§7 e §8 do escopo).
 *
 * O histórico é aditivo: nenhum evento sobrescreve o anterior, o que permite
 * reconstruir a posição jurídica da garantia em qualquer data.
 *
 * `DocumentaryEvidence` é o evento que não muda nada: registra que outro
 * documento passou a comprovar a garantia. Sem ele, um documento que apenas
 * confirma o cadastro teria de virar uma "alteração" fictícia para deixar
 * rastro — poluindo o histórico com mudanças que nunca houve (§10).
 */
enum GuaranteeEventType: string
{
    case Constitution = 'constitution';
    case Amendment = 'amendment';
    case Reinforcement = 'reinforcement';
    case Substitution = 'substitution';
    case Release = 'release';
    case Registration = 'registration';
    case Revaluation = 'revaluation';
    case Suspension = 'suspension';
    case Termination = 'termination';
    case StatusChange = 'status_change';
    case DocumentaryEvidence = 'documentary_evidence';

    public function label(): string
    {
        return match ($this) {
            self::Constitution => 'Constituição',
            self::Amendment => 'Alteração',
            self::Reinforcement => 'Reforço',
            self::Substitution => 'Substituição',
            self::Release => 'Liberação',
            self::Registration => 'Registro',
            self::Revaluation => 'Reavaliação',
            self::Suspension => 'Suspensão',
            self::Termination => 'Encerramento',
            self::StatusChange => 'Alteração de situação',
            self::DocumentaryEvidence => 'Comprovação documental',
        };
    }

    /**
     * Situação jurídica que o evento impõe à garantia, quando ele por si só a
     * determina. Alteração e reforço não mudam a situação — só os valores.
     */
    public function resultingLegalStatus(): ?GuaranteeLegalStatus
    {
        return match ($this) {
            self::Constitution => GuaranteeLegalStatus::Active,
            self::Substitution => GuaranteeLegalStatus::Substituted,
            self::Release => GuaranteeLegalStatus::Released,
            self::Termination => GuaranteeLegalStatus::Terminated,
            self::Suspension => GuaranteeLegalStatus::Suspended,
            default => null,
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Constitution, self::Registration, self::Reinforcement => 'success',
            self::Amendment, self::Revaluation, self::StatusChange => 'info',
            self::DocumentaryEvidence => 'gray',
            self::Substitution, self::Suspension => 'warning',
            self::Release, self::Termination => 'gray',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $event) {
            $options[$event->value] = $event->label();
        }

        return $options;
    }
}
