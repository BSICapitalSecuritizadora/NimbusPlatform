<?php

namespace App\Enums;

/**
 * Situação do ajuste financeiro que uma revisão pede em um empreendimento.
 *
 * Derivada -- nunca gravada -- da diferença congelada da revisão, dos
 * pagamentos da família e da própria revisão e da situação do fluxo dela, para
 * não envelhecer. O sistema não tem devolução, estorno nem compensação: o
 * valor pago a maior só pode ser reconhecido por decisão expressa
 * (justificativa na etapa Pagamento e aceite do Finalizador), e continua em
 * aberto depois disso. Nada aqui significa "quitado" sem que o saldo esteja de
 * fato zerado.
 *
 * "Complementar" é só o pagamento que completa o que a família já pagou antes
 * da revisão; sem pagamento anterior, o pagamento é o da própria revisão.
 */
enum MeasurementRevisionAdjustmentStatus: string
{
    case ReferenceUnavailable = 'reference_unavailable';
    case NoAdjustment = 'no_adjustment';
    case AwaitingRevisionPayment = 'awaiting_revision_payment';
    case SettledByRevisionPayment = 'settled_by_revision_payment';
    case AwaitingSupplementaryPayment = 'awaiting_supplementary_payment';
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
            self::NoAdjustment => 'Sem ajuste pendente',
            self::AwaitingRevisionPayment => 'Pagamento da revisão pendente',
            self::SettledByRevisionPayment => 'Quitada pelo pagamento da revisão',
            self::AwaitingSupplementaryPayment => 'Pagamento complementar pendente',
            self::SettledBySupplementaryPayment => 'Quitada por pagamento complementar',
            self::OmissionAccepted => 'Ausência de pagamento aceita',
            self::OverpaymentPendingDecision => 'Valor pago a maior — decisão pendente',
            self::OverpaymentAcceptedUnrecovered => 'Valor pago a maior reconhecido — sem devolução registrada',
            self::OverpaymentPreviouslyAccepted => 'Valor pago a maior já aceito anteriormente — sem devolução registrada',
            self::PositionSuperseded => 'Posição substituída pela revisão seguinte',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::NoAdjustment, self::SettledBySupplementaryPayment, self::SettledByRevisionPayment => 'success',
            self::AwaitingSupplementaryPayment, self::AwaitingRevisionPayment, self::OverpaymentPendingDecision => 'warning',
            self::OmissionAccepted, self::OverpaymentAcceptedUnrecovered, self::OverpaymentPreviouslyAccepted => 'danger',
            self::PositionSuperseded, self::ReferenceUnavailable => 'gray',
        };
    }

    /**
     * A posição financeira revisada ainda não está resolvida: há valor a
     * completar sobre o que a família já pagou, ausência de pagamento aceita,
     * ou valor pago a maior sem devolução -- mesmo depois da decisão expressa.
     * O pagamento pendente da revisão que nada tinha de pago antes é só a etapa
     * Pagamento em andamento, e a posição substituída vale na revisão seguinte.
     */
    public function isUnresolved(): bool
    {
        return match ($this) {
            self::AwaitingSupplementaryPayment,
            self::OmissionAccepted,
            self::OverpaymentPendingDecision,
            self::OverpaymentAcceptedUnrecovered,
            self::OverpaymentPreviouslyAccepted => true,
            default => false,
        };
    }
}
