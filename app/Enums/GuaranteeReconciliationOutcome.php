<?php

namespace App\Enums;

/**
 * O que um novo documento representa diante das garantias já cadastradas.
 *
 * Existe para separar quatro situações que a tela antiga tratava como uma só:
 * o documento pode acrescentar informação que faltava, confirmar o que já
 * havia, alterar o que estava vigente ou contradizer o cadastro. Só a última é
 * conflito — chamar as três primeiras de conflito empurra o revisor a criar uma
 * garantia duplicada quando o correto é enriquecer a existente.
 */
enum GuaranteeReconciliationOutcome: string
{
    case NewGuarantee = 'new_guarantee';
    case Complement = 'complement';
    case Confirmation = 'confirmation';
    case Change = 'change';
    case Conflict = 'conflict';

    public function label(): string
    {
        return match ($this) {
            self::NewGuarantee => 'Garantia ainda não cadastrada',
            self::Complement => 'Informações complementares encontradas',
            self::Confirmation => 'Garantia confirmada em novo documento',
            self::Change => 'Alteração documental detectada',
            self::Conflict => 'Conflito documental — revisão necessária',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::NewGuarantee => 'info',
            self::Complement => 'success',
            self::Confirmation => 'gray',
            self::Change => 'warning',
            self::Conflict => 'danger',
        };
    }

    /**
     * Ação que a tela de revisão deve oferecer em primeiro lugar.
     *
     * Havendo garantia correspondente, complementar vem antes de criar: a mesma
     * garantia aparece em vários instrumentos, e um documento novo não é uma
     * garantia nova.
     */
    public function recommendedAction(): string
    {
        return match ($this) {
            self::NewGuarantee => GuaranteeReviewTransition::Approve->value,
            default => GuaranteeReviewTransition::Complement->value,
        };
    }

    public function recommendedActionLabel(): string
    {
        return match ($this) {
            self::NewGuarantee => 'Criar garantia',
            self::Conflict => 'Revisar divergências',
            default => 'Complementar garantia existente',
        };
    }

    /** Há uma garantia existente que a candidata provavelmente é? */
    public function pointsToExistingGuarantee(): bool
    {
        return $this !== self::NewGuarantee;
    }

    /**
     * A situação exige atenção humana antes de qualquer aplicação?
     *
     * Complemento e confirmação continuam passando por confirmação humana (§21
     * do escopo), mas não são sinalizados como pendência: o que aqui se marca é
     * o que o revisor precisa *decidir*, não o que ele precisa apenas aprovar.
     */
    public function requiresAttention(): bool
    {
        return $this === self::Conflict || $this === self::Change;
    }

    public function description(): string
    {
        return match ($this) {
            self::NewGuarantee => 'Nenhuma garantia cadastrada corresponde à identificada neste documento.',
            self::Complement => 'A garantia já existe e o documento traz informações que ainda não constavam no cadastro.',
            self::Confirmation => 'O documento repete informações que já constam no cadastro. Vale como nova fonte documental.',
            self::Change => 'O documento altera informação vigente da garantia. A posição anterior é preservada no histórico.',
            self::Conflict => 'O documento traz informação divergente da cadastrada sem indicar alteração. Alguém precisa decidir.',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $outcome) {
            $options[$outcome->value] = $outcome->label();
        }

        return $options;
    }
}
