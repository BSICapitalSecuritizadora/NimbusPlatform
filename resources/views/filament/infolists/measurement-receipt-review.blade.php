<div class="space-y-3 text-sm text-gray-950 dark:text-white">
    @if ($evidence)
        <dl class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div><dt>Versão</dt><dd>v{{ $evidence->version }}</dd></div>
            <div><dt>Nome original</dt><dd class="break-all">{{ $evidence->original_filename ?? 'Nome original não registrado no fluxo legado' }}</dd></div>
            <div><dt>Enviado por</dt><dd>{{ $evidence->uploadedByUser?->name ?? 'Não registrado' }}</dd></div>
            <div><dt>Data do upload</dt><dd>{{ $evidence->uploaded_at?->format('d/m/Y H:i:s') ?? 'Não registrada' }}</dd></div>
            <div><dt>Valor do pagamento</dt><dd>R$ {{ number_format((float) $evidence->payment->amount, 2, ',', '.') }}</dd></div>
            <div><dt>Data e método</dt><dd>{{ $evidence->payment->pay_date?->format('d/m/Y') }} · {{ $evidence->payment->method ?? 'Não informado' }}</dd></div>
            <div class="sm:col-span-2"><dt>SHA-256 da versão</dt><dd class="break-all">{{ $evidence->sha256 ?? 'Hash não registrado no fluxo legado' }}</dd></div>
        </dl>
        <x-filament::link :href="route('admin.measurements.receipt-evidences.download', ['payment' => $evidence->measurement_payment_id, 'evidence' => $evidence])" target="_blank" rel="noopener noreferrer">
            Abrir comprovante · v{{ $evidence->version }}
        </x-filament::link>
    @else
        <p>Selecione uma versão para conferir os dados e abrir o comprovante.</p>
    @endif
</div>
