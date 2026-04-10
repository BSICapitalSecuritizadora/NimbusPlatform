<div class="space-y-6">
    <section class="bsi-shell-card p-6 lg:p-8">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div class="max-w-3xl">
                <div class="bsi-kicker mb-2">Acompanhamento</div>
                <h1 class="text-3xl font-semibold tracking-[-0.04em] text-brand-800">Minhas emissões</h1>
                <p class="mt-3 text-sm leading-7 text-zinc-600">
                    Acompanhe as emissões vinculadas ao seu cadastro com uma leitura mais clara dos principais dados operacionais.
                </p>
            </div>

            <div class="flex flex-wrap gap-3">
                <span class="bsi-portal-meta">
                    <flux:icon.building-office-2 class="size-4" />
                    <span>{{ $emissions->count() }} operação(ões)</span>
                </span>
                <flux:button variant="subtle" as="a" href="{{ route('investor.documents') }}" class="!rounded-full !px-5">
                    Ver documentos
                </flux:button>
            </div>
        </div>
    </section>

    @if ($emissions->isEmpty())
        <section class="bsi-shell-card p-10 text-center">
            <span class="mx-auto flex size-16 items-center justify-center rounded-[24px] bg-brand-50 text-brand-700">
                <flux:icon.building-office-2 class="size-8" />
            </span>
            <h2 class="mt-5 text-2xl font-semibold tracking-[-0.04em] text-brand-800">Nenhuma emissão vinculada</h2>
            <p class="mx-auto mt-3 max-w-xl text-sm leading-7 text-zinc-600">
                Quando houver emissões associadas ao seu cadastro, elas aparecerão aqui com os principais dados para consulta rápida.
            </p>
        </section>
    @else
        <section class="grid gap-4 xl:grid-cols-2">
            @foreach ($emissions as $emission)
                @php
                    $statusClasses = match ($emission->status) {
                        'active' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
                        'closed' => 'border-red-200 bg-red-50 text-red-700',
                        default => 'border-amber-200 bg-amber-50 text-amber-700',
                    };
                @endphp

                <article class="bsi-shell-card p-6" wire:key="emission-{{ $emission->id }}">
                    <div class="flex flex-col gap-5">
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="inline-flex items-center rounded-full bg-gold-400/15 px-3 py-1 text-xs font-semibold uppercase tracking-[0.18em] text-gold-600">
                                        {{ $emission->type }}
                                    </span>
                                    <span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold {{ $statusClasses }}">
                                        {{ $emission->status_label }}
                                    </span>
                                </div>
                                <h2 class="mt-4 text-2xl font-semibold tracking-[-0.04em] text-brand-800">{{ $emission->name }}</h2>
                                <p class="mt-2 text-sm text-zinc-500">
                                    IF {{ $emission->if_code ?? '—' }} · ISIN {{ $emission->isin_code ?? '—' }}
                                </p>
                            </div>

                            <div class="flex size-16 flex-shrink-0 items-center justify-center overflow-hidden rounded-[24px] border border-brand-100 bg-brand-50/70 p-3">
                                @if($emission->logo_path)
                                    <img src="{{ Storage::url($emission->logo_path) }}" alt="{{ $emission->name }}" class="h-full w-full object-contain">
                                @else
                                    <flux:icon.chart-bar class="size-7 text-brand-700" />
                                @endif
                            </div>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="rounded-[22px] border border-zinc-200/80 bg-zinc-50/70 p-4">
                                <div class="text-xs font-semibold uppercase tracking-[0.18em] text-zinc-400">Emissor</div>
                                <div class="mt-2 text-sm font-semibold text-zinc-800">{{ $emission->issuer ?? 'Não informado' }}</div>
                            </div>
                            <div class="rounded-[22px] border border-zinc-200/80 bg-zinc-50/70 p-4">
                                <div class="text-xs font-semibold uppercase tracking-[0.18em] text-zinc-400">Remuneração</div>
                                <div class="mt-2 text-sm font-semibold text-zinc-800">{{ $emission->remuneration ?? 'Não informada' }}</div>
                            </div>
                            <div class="rounded-[22px] border border-zinc-200/80 bg-zinc-50/70 p-4">
                                <div class="text-xs font-semibold uppercase tracking-[0.18em] text-zinc-400">Emissão</div>
                                <div class="mt-2 text-sm font-semibold text-zinc-800">{{ $emission->issue_date?->format('d/m/Y') ?? 'Não informada' }}</div>
                            </div>
                            <div class="rounded-[22px] border border-zinc-200/80 bg-zinc-50/70 p-4">
                                <div class="text-xs font-semibold uppercase tracking-[0.18em] text-zinc-400">Vencimento</div>
                                <div class="mt-2 text-sm font-semibold text-zinc-800">{{ $emission->maturity_date?->format('d/m/Y') ?? 'Não informado' }}</div>
                            </div>
                        </div>

                        <div class="flex flex-col gap-3 border-t border-zinc-200/80 pt-4 sm:flex-row sm:items-center sm:justify-between">
                            <p class="text-sm text-zinc-500">
                                Volume emitido: <span class="font-semibold text-zinc-800">{{ $emission->issued_volume ? 'R$ '.number_format((float) $emission->issued_volume, 2, ',', '.') : 'Não informado' }}</span>
                            </p>

                            @if($emission->if_code)
                                <flux:button variant="subtle" as="a" href="{{ route('site.emissions.show', $emission->if_code) }}" class="!rounded-full !px-5">
                                    Ver operação
                                </flux:button>
                            @endif
                        </div>
                    </div>
                </article>
            @endforeach
        </section>
    @endif
</div>
