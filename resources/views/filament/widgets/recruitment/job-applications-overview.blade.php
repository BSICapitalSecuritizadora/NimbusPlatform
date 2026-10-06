<div class="bsi-job-applications-kpi-bar w-full overflow-hidden rounded-xl border border-[var(--border-subtle)] bg-[var(--surface-card)] shadow-xs mb-5">
    <div class="grid grid-cols-2 divide-y divide-[var(--border-subtle)] sm:grid-cols-3 sm:divide-y-0 sm:divide-x sm:divide-[var(--border-subtle)] lg:grid-cols-5">
        {{-- Total de Candidaturas --}}
        <div class="flex flex-col p-4 sm:p-5">
            <span class="text-[0.6875rem] font-semibold uppercase tracking-wider text-[var(--text-muted)]">Total de Candidaturas</span>
            <span class="mt-2 font-mono text-2xl sm:text-3xl font-bold tracking-tight text-[var(--text-primary)]">{{ number_format($metrics['total'], 0, ',', '.') }}</span>
            <span class="mt-1 text-xs text-[var(--text-muted)]">Base consolidada</span>
        </div>

        {{-- Novas --}}
        <div class="flex flex-col p-4 sm:p-5">
            <span class="text-[0.6875rem] font-semibold uppercase tracking-wider text-[var(--text-muted)]">Novas</span>
            <div class="mt-2 flex items-baseline gap-2">
                <span @class([
                    'font-mono text-2xl sm:text-3xl font-bold tracking-tight',
                    'text-amber-600 dark:text-amber-400' => $metrics['new'] > 0,
                    'text-[var(--text-primary)]' => $metrics['new'] === 0,
                ])>{{ number_format($metrics['new'], 0, ',', '.') }}</span>
                @if ($metrics['new'] > 0)
                    <span class="inline-flex items-center rounded-full bg-amber-500/15 border border-amber-500/30 px-2 py-0.5 text-[0.6875rem] font-medium text-amber-700 dark:text-amber-300">Aguardando</span>
                @endif
            </div>
            <span class="mt-1 text-xs text-[var(--text-muted)]">Aguardando triagem</span>
        </div>

        {{-- Em Triagem --}}
        <div class="flex flex-col p-4 sm:p-5">
            <span class="text-[0.6875rem] font-semibold uppercase tracking-wider text-[var(--text-muted)]">Em Triagem</span>
            <div class="mt-2 flex items-baseline gap-2">
                <span @class([
                    'font-mono text-2xl sm:text-3xl font-bold tracking-tight',
                    'text-sky-600 dark:text-sky-400' => $metrics['screening'] > 0,
                    'text-[var(--text-primary)]' => $metrics['screening'] === 0,
                ])>{{ number_format($metrics['screening'], 0, ',', '.') }}</span>
            </div>
            <span class="mt-1 text-xs text-[var(--text-muted)]">Análise de perfil</span>
        </div>

        {{-- Em Entrevista --}}
        <div class="flex flex-col p-4 sm:p-5">
            <span class="text-[0.6875rem] font-semibold uppercase tracking-wider text-[var(--text-muted)]">Em Entrevista</span>
            <div class="mt-2 flex items-baseline gap-2">
                <span @class([
                    'font-mono text-2xl sm:text-3xl font-bold tracking-tight',
                    'text-indigo-600 dark:text-indigo-400' => $metrics['interview'] > 0,
                    'text-[var(--text-primary)]' => $metrics['interview'] === 0,
                ])>{{ number_format($metrics['interview'], 0, ',', '.') }}</span>
            </div>
            <span class="mt-1 text-xs text-[var(--text-muted)]">Rodadas ativas</span>
        </div>

        {{-- Finalistas --}}
        <div class="col-span-2 flex flex-col p-4 sm:col-span-1 sm:p-5">
            <span class="text-[0.6875rem] font-semibold uppercase tracking-wider text-[var(--text-muted)]">Finalistas</span>
            <div class="mt-2 flex items-baseline gap-2">
                <span @class([
                    'font-mono text-2xl sm:text-3xl font-bold tracking-tight',
                    'text-emerald-600 dark:text-emerald-400' => $metrics['finalist'] > 0,
                    'text-[var(--text-primary)]' => $metrics['finalist'] === 0,
                ])>{{ number_format($metrics['finalist'], 0, ',', '.') }}</span>
                @if ($metrics['finalist'] > 0)
                    <span class="inline-flex items-center rounded-full bg-emerald-500/15 border border-emerald-500/30 px-2 py-0.5 text-[0.6875rem] font-medium text-emerald-700 dark:text-emerald-300">Etapa Final</span>
                @endif
            </div>
            <span class="mt-1 text-xs text-[var(--text-muted)]">Etapa decisória</span>
        </div>
    </div>
</div>
