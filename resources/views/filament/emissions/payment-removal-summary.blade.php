@php
    /** @var \App\Models\Payment $payment */
    $currency = fn (mixed $value): string => \Illuminate\Support\Number::currency((float) $value, 'BRL', config('app.locale'));
@endphp

<div class="space-y-3 text-sm">
    <dl class="divide-y divide-gray-200 rounded-xl border border-gray-200 dark:divide-gray-700 dark:border-gray-700">
        @foreach ([
            'Data' => $payment->payment_date?->format('d/m/Y'),
            'Prêmio' => $currency($payment->premium_value),
            'Juros' => $currency($payment->interest_value),
            'Amortização' => $currency($payment->amortization_value),
            'Amortização Extra' => $currency($payment->extra_amortization_value),
        ] as $label => $value)
            <div class="flex items-center justify-between gap-4 px-4 py-2">
                <dt class="text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                <dd class="font-medium tabular-nums text-gray-900 dark:text-gray-100">{{ $value }}</dd>
            </div>
        @endforeach
    </dl>

    <p class="text-gray-600 dark:text-gray-300">Esta ação removerá este registro do cronograma de pagamentos.</p>
</div>
