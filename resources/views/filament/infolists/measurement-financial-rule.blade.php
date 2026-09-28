<dl class="grid gap-4 text-sm sm:grid-cols-2">
    <div><dt class="text-gray-500 dark:text-gray-400">Emissão</dt><dd>{{ $rule->emission?->name }}</dd></div>
    <div><dt class="text-gray-500 dark:text-gray-400">Obra</dt><dd>{{ $rule->construction?->development_name ?? 'Todas as obras da emissão' }}</dd></div>
    <div><dt class="text-gray-500 dark:text-gray-400">Regra</dt><dd>{{ $rule->name }} · versão {{ $rule->version }}</dd></div>
    <div><dt class="text-gray-500 dark:text-gray-400">Direção</dt><dd>{{ \App\Models\MeasurementFinancialRule::DIRECTIONS[$rule->direction] }}</dd></div>
    <div><dt class="text-gray-500 dark:text-gray-400">Limite em reais</dt><dd>{{ \App\Services\MeasurementFinancialReconciliationService::formatCurrency($rule->maximum_difference_amount) }}</dd></div>
    <div><dt class="text-gray-500 dark:text-gray-400">Limite percentual</dt><dd>{{ $rule->maximum_difference_percent !== null ? str_replace('.', ',', $rule->maximum_difference_percent).'%' : 'Não definido' }}</dd></div>
    <div><dt class="text-gray-500 dark:text-gray-400">Vigência pela data do pagamento</dt><dd>{{ $rule->effective_from->format('d/m/Y') }} até {{ $rule->effective_until?->format('d/m/Y') ?? 'prazo indeterminado' }}</dd></div>
    <div><dt class="text-gray-500 dark:text-gray-400">Documento de suporte</dt><dd>{{ $rule->requires_document ? 'Obrigatório' : 'Opcional' }}</dd></div>
    <div class="sm:col-span-2"><dt class="text-gray-500 dark:text-gray-400">Condições de aplicação</dt><dd class="whitespace-pre-wrap break-words">{{ $rule->description }}</dd></div>
    <div><dt class="text-gray-500 dark:text-gray-400">Registrada por</dt><dd>{{ $rule->createdBy?->name }} · {{ $rule->created_at?->format('d/m/Y H:i') }}</dd></div>
    <div><dt class="text-gray-500 dark:text-gray-400">Encerramento</dt><dd>{{ $rule->retired_at?->format('d/m/Y H:i') ?? 'Não encerrada' }}</dd></div>
</dl>
