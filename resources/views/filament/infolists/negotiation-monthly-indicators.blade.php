@php
    $record = $getRecord();
    $sales = (int) ($record?->sales ?? 0);
    $cancellations = (int) ($record?->cancellations ?? 0);
    $hasSales = $sales > 0;
    $hasCancellations = $cancellations > 0;
@endphp

<div class="grid grid-cols-1 md:grid-cols-2 divide-y md:divide-y-0 md:divide-x divide-slate-200/20 dark:divide-white/10 -mx-6 -my-4 sm:-mx-6 sm:-my-4">
    <!-- Vendas Indicator -->
    <div class="flex flex-col justify-center px-6 py-5 md:py-6 md:px-8">
        <span class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-[rgba(251,250,248,0.65)]">
            Vendas
        </span>
        <div class="mt-2 flex items-baseline gap-2.5">
            <span class="text-3xl lg:text-4xl font-bold tracking-tight font-mono {{ $hasSales ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-900 dark:text-[#fbfaf8]' }}">
                {{ number_format($sales, 0, ',', '.') }}
            </span>
            @if ($hasSales)
                <span class="inline-flex items-center text-xs font-medium text-emerald-600 dark:text-emerald-400 bg-emerald-500/10 px-2 py-0.5 rounded-full">
                    <x-filament::icon icon="heroicon-m-arrow-trending-up" class="h-3.5 w-3.5 mr-1" />
                    +{{ $sales }}
                </span>
            @endif
        </div>
        <span class="mt-1 text-xs text-slate-500 dark:text-[rgba(251,250,248,0.55)]">
            {{ $sales === 1 ? '1 nova venda na competência' : "{$sales} novas vendas na competência" }}
        </span>
    </div>

    <!-- Distratos Indicator -->
    <div class="flex flex-col justify-center px-6 py-5 md:py-6 md:px-8">
        <span class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-[rgba(251,250,248,0.65)]">
            Distratos
        </span>
        <div class="mt-2 flex items-baseline gap-2.5">
            <span class="text-3xl lg:text-4xl font-bold tracking-tight font-mono {{ $hasCancellations ? 'text-rose-600 dark:text-rose-400' : 'text-slate-900 dark:text-[#fbfaf8]' }}">
                {{ number_format($cancellations, 0, ',', '.') }}
            </span>
            @if ($hasCancellations)
                <span class="inline-flex items-center text-xs font-medium text-rose-600 dark:text-rose-400 bg-rose-500/10 px-2 py-0.5 rounded-full">
                    <x-filament::icon icon="heroicon-m-arrow-trending-down" class="h-3.5 w-3.5 mr-1" />
                    -{{ $cancellations }}
                </span>
            @endif
        </div>
        <span class="mt-1 text-xs text-slate-500 dark:text-[rgba(251,250,248,0.55)]">
            {{ $cancellations === 1 ? '1 distrato na competência' : "{$cancellations} distratos na competência" }}
        </span>
    </div>
</div>
