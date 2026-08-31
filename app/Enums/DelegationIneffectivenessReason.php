<?php

namespace App\Enums;

/**
 * Por que uma delegação vigente não confere autoridade nenhuma.
 *
 * Uma delegação pode estar dentro do período, não revogada, e ainda assim não
 * valer: o delegante perdeu a responsabilidade, o delegado foi desativado, a
 * permissão exigida saiu da role. Até aqui a interface dizia só "Ineficaz", e
 * descobrir a causa exigia consultar o banco -- embora quem decide a efetividade
 * já soubesse qual condição falhou.
 *
 * O valor é estável e serve para teste e filtro; o rótulo é para o operador e
 * pode mudar sem quebrar nada. Nenhum deles nomeia usuário, permissão ou id: a
 * causa basta para agir, e a superfície é operacional, não técnica.
 */
enum DelegationIneffectivenessReason: string
{
    case DelegatorInactive = 'delegator_inactive';
    case DelegatorUnapproved = 'delegator_unapproved';
    case DelegatorMissingPermission = 'delegator_missing_permission';
    case DelegatorMissingAssignment = 'delegator_missing_assignment';
    case DelegateInactive = 'delegate_inactive';
    case DelegateUnapproved = 'delegate_unapproved';
    case DelegateMissingPermission = 'delegate_missing_permission';
    case ScopeMismatch = 'scope_mismatch';

    public function label(): string
    {
        return match ($this) {
            self::DelegatorInactive => 'O delegante está inativo.',
            self::DelegatorUnapproved => 'O delegante não está aprovado.',
            self::DelegatorMissingPermission => 'O delegante não possui mais a permissão exigida pela responsabilidade delegada.',
            self::DelegatorMissingAssignment => 'O delegante não é mais o responsável direto por nenhuma operação no escopo delegado.',
            self::DelegateInactive => 'O delegado está inativo.',
            self::DelegateUnapproved => 'O delegado não está aprovado.',
            self::DelegateMissingPermission => 'O delegado não possui a permissão exigida pela responsabilidade delegada.',
            self::ScopeMismatch => 'O escopo delegado não corresponde a nenhuma responsabilidade do fluxo de medição.',
        };
    }
}
