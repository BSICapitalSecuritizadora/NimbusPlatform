<?php

namespace App\Actions\Emissions;

use App\Enums\PaymentRemovalOutcome;
use App\Models\Emission;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * Remove uma data do Cronograma de Pagamentos de uma emissão.
 *
 * A linha é localizada pela chave primária e sempre dentro da própria emissão:
 * a data não identifica nada -- `payments` não tem unique em (emission_id,
 * payment_date) e duas emissões podem pagar no mesmo dia. A linha só é apagada
 * se ainda mostrar exatamente o que o usuário confirmou, e a exclusão e o
 * registro do LogsActivity (evento `deleted`, com os valores anteriores)
 * acontecem na mesma transação: não existe remoção sem auditoria.
 */
class RemovePaymentFromSchedule
{
    public function handle(Emission $emission, int|string $paymentId, string $confirmedSnapshot): PaymentRemovalOutcome
    {
        return DB::transaction(function () use ($emission, $paymentId, $confirmedSnapshot): PaymentRemovalOutcome {
            $payment = $emission->payments()->whereKey($paymentId)->lockForUpdate()->first();

            if ($payment === null) {
                return PaymentRemovalOutcome::Unavailable;
            }

            if (self::snapshot($payment) !== $confirmedSnapshot) {
                return PaymentRemovalOutcome::Changed;
            }

            $payment->delete();

            return PaymentRemovalOutcome::Removed;
        });
    }

    /**
     * A data e os quatro valores da linha, na forma persistida: é o que a
     * confirmação mostra ao usuário.
     */
    public static function snapshot(Payment $payment): string
    {
        return implode('|', [
            $payment->payment_date?->toDateString(),
            $payment->premium_value,
            $payment->interest_value,
            $payment->amortization_value,
            $payment->extra_amortization_value,
        ]);
    }
}
