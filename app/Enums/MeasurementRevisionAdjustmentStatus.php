<?php

namespace App\Enums;

/**
 * Situação do ajuste financeiro que uma revisão pede em um empreendimento.
 *
 * Derivada -- nunca gravada -- da diferença congelada da revisão, dos
 * pagamentos da família e da própria revisão e da situação do fluxo dela, para
 * não envelhecer. Três conceitos que nunca se confundem:
 *
 * - identificada: o sistema calculou, de dados autoritativos (pagamentos
 *   registrados e valores aprovados), que a família pagou mais ou menos do que
 *   a revisão aprova;
 * - reconhecida: alguém registrou a decisão financeira (nota da aprovação da
 *   etapa Pagamento) e o Finalizador a aceitou expressamente na Finalização.
 *   Reconhecer não devolve, não compensa nem regulariza nada;
 * - regularizada: um evento financeiro real, registrado no sistema, zerou a
 *   diferença -- hoje, só o pagamento complementar (ou o da própria revisão)
 *   com a Finalização concluída. O sistema não tem devolução, estorno nem
 *   compensação: valor pago a maior reconhecido continua em aberto.
 *
 * "Complementar" é só o pagamento que completa o que a família já pagou antes
 * da revisão; sem pagamento anterior, o pagamento é o da própria revisão.
 */
enum MeasurementRevisionAdjustmentStatus: string
{
    case ReferenceUnavailable = 'reference_unavailable';
    case NoAdjustment = 'no_adjustment';
    case AwaitingRevisionPayment = 'awaiting_revision_payment';
    case RevisionPaymentRegistered = 'revision_payment_registered';
    case SettledByRevisionPayment = 'settled_by_revision_payment';
    case AwaitingSupplementaryPayment = 'awaiting_supplementary_payment';
    case PartialSupplementaryPayment = 'partial_supplementary_payment';
    case SupplementaryPaymentRegistered = 'supplementary_payment_registered';
    case SettledBySupplementaryPayment = 'settled_by_supplementary_payment';
    case OmissionAccepted = 'omission_accepted';
    case OverpaymentPendingDecision = 'overpayment_pending_decision';
    case OverpaymentAcceptedUnrecovered = 'overpayment_accepted_unrecovered';
    case OverpaymentPreviouslyAccepted = 'overpayment_previously_accepted';
    case PositionSuperseded = 'position_superseded';

    public function label(): string
    {
        return match ($this) {
            self::ReferenceUnavailable => 'Sem referência financeira',
            self::NoAdjustment => 'Sem diferença a regularizar',
            self::AwaitingRevisionPayment => 'Pagamento da revisão pendente',
            self::RevisionPaymentRegistered => 'Pagamento da revisão registrado — conclusão na Finalização',
            self::SettledByRevisionPayment => 'Paga pelo pagamento da revisão',
            self::AwaitingSupplementaryPayment => 'Pagamento complementar pendente',
            self::PartialSupplementaryPayment => 'Pagamento complementar parcial — saldo a pagar pendente',
            self::SupplementaryPaymentRegistered => 'Pagamento complementar registrado — conclusão na Finalização',
            self::SettledBySupplementaryPayment => 'Regularizada por pagamento complementar',
            self::OmissionAccepted => 'Saldo a pagar reconhecido — regularização financeira pendente',
            self::OverpaymentPendingDecision => 'Valor pago a maior identificado — reconhecimento pendente',
            self::OverpaymentAcceptedUnrecovered => 'Valor pago a maior reconhecido — regularização financeira pendente, sem devolução registrada',
            self::OverpaymentPreviouslyAccepted => 'Valor pago a maior reconhecido anteriormente — regularização financeira pendente, sem devolução registrada',
            self::PositionSuperseded => 'Posição substituída pela revisão seguinte',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::NoAdjustment, self::SettledBySupplementaryPayment, self::SettledByRevisionPayment => 'success',
            self::AwaitingSupplementaryPayment, self::AwaitingRevisionPayment, self::OverpaymentPendingDecision,
            self::PartialSupplementaryPayment => 'warning',
            self::RevisionPaymentRegistered, self::SupplementaryPaymentRegistered => 'info',
            self::OmissionAccepted, self::OverpaymentAcceptedUnrecovered, self::OverpaymentPreviouslyAccepted => 'danger',
            self::PositionSuperseded, self::ReferenceUnavailable => 'gray',
        };
    }

    /**
     * A posição financeira revisada ainda não está resolvida: há valor a
     * completar sobre o que a família já pagou, ausência de pagamento aceita,
     * ou valor pago a maior sem devolução -- mesmo depois do reconhecimento
     * expresso. O pagamento pendente da revisão que nada tinha de pago antes é
     * só a etapa Pagamento em andamento, o pagamento já registrado espera só a
     * Finalização, e a posição substituída vale na revisão seguinte.
     */
    public function isUnresolved(): bool
    {
        return match ($this) {
            self::AwaitingSupplementaryPayment,
            self::PartialSupplementaryPayment,
            self::OmissionAccepted,
            self::OverpaymentPendingDecision,
            self::OverpaymentAcceptedUnrecovered,
            self::OverpaymentPreviouslyAccepted => true,
            default => false,
        };
    }

    /**
     * Diferença identificada: a família pagou mais ou menos do que a revisão
     * aprova, ou a regularização já aconteceu por pagamento.
     */
    public function isIdentified(): bool
    {
        return match ($this) {
            self::ReferenceUnavailable, self::NoAdjustment, self::PositionSuperseded,
            self::AwaitingRevisionPayment, self::RevisionPaymentRegistered, self::SettledByRevisionPayment => false,
            default => true,
        };
    }

    /**
     * Diferença reconhecida expressamente -- decisão registrada e aceite do
     * Finalizador, nesta revisão ou numa finalização anterior da família. Não é
     * regularização.
     */
    public function isAcknowledged(): bool
    {
        return match ($this) {
            self::OmissionAccepted,
            self::OverpaymentAcceptedUnrecovered,
            self::OverpaymentPreviouslyAccepted => true,
            default => false,
        };
    }

    /**
     * Regularizada por um evento financeiro registrado (pagamento) e com a
     * Finalização concluída -- nunca por reconhecimento.
     */
    public function isSettled(): bool
    {
        return $this === self::SettledBySupplementaryPayment || $this === self::SettledByRevisionPayment;
    }

    /**
     * O rótulo do reconhecimento para a tela: identificada e ainda sem
     * reconhecimento, reconhecida, ou nada a reconhecer.
     */
    public function acknowledgmentLabel(): string
    {
        // O pagamento complementar registrado cobre o saldo: nada a reconhecer,
        // só a Finalização que confere os comprovantes.
        return match (true) {
            ! $this->isIdentified() || $this->isSettled() || $this === self::SupplementaryPaymentRegistered => 'Não se aplica',
            $this->isAcknowledged() => 'Diferença reconhecida',
            default => 'Diferença identificada — reconhecimento pendente',
        };
    }

    /**
     * O rótulo da regularização financeira: pendente enquanto a diferença
     * existe (reconhecida ou não), concluída só por pagamento registrado com a
     * Finalização, e "não se aplica" sem diferença.
     */
    public function regularizationLabel(): string
    {
        return match (true) {
            $this->isSettled() => 'Regularização concluída',
            $this === self::SupplementaryPaymentRegistered => 'Regularização em conclusão (pagamento complementar registrado)',
            $this->isUnresolved() => 'Regularização financeira pendente',
            default => 'Não se aplica',
        };
    }
}
