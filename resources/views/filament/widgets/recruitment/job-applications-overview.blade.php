<div class="bsi-job-applications-kpi-bar w-full overflow-hidden rounded-xl border border-slate-700/40 bg-[#0d252e] shadow-sm mb-5">
    <div class="grid grid-cols-2 divide-y divide-slate-700/30 sm:grid-cols-3 sm:divide-y-0 sm:divide-x lg:grid-cols-5">
        {{-- Total de Candidaturas --}}
        <div class="flex flex-col p-4 sm:p-5">
            <span class="text-[0.6875rem] font-semibold uppercase tracking-wider text-slate-400">Total de Candidaturas</span>
            <span class="mt-2 font-mono text-2xl sm:text-3xl font-bold tracking-tight text-white">{{ number_format($metrics['total'], 0, ',', '.') }}</span>
            <span class="mt-1 text-xs text-slate-400">Base consolidada</span>
        </div>

        {{-- Novas --}}
        <div class="flex flex-col p-4 sm:p-5">
            <span class="text-[0.6875rem] font-semibold uppercase tracking-wider text-slate-400">Novas</span>
            <div class="mt-2 flex items-baseline gap-2">
                <span @class([
                    'font-mono text-2xl sm:text-3xl font-bold tracking-tight',
                    'text-amber-400' => $metrics['new'] > 0,
                    'text-white' => $metrics['new'] === 0,
                ])>{{ number_format($metrics['new'], 0, ',', '.') }}</span>
                @if ($metrics['new'] > 0)
                    <span class="inline-flex items-center rounded-full bg-amber-500/15 px-2 py-0.5 text-[0.6875rem] font-medium text-amber-300">Aguardando</span>
                @endif
            </div>
            <span class="mt-1 text-xs text-slate-400">Aguardando triagem</span>
        </div>

        {{-- Em Triagem --}}
        <div class="flex flex-col p-4 sm:p-5">
            <span class="text-[0.6875rem] font-semibold uppercase tracking-wider text-slate-400">Em Triagem</span>
            <div class="mt-2 flex items-baseline gap-2">
                <span @class([
                    'font-mono text-2xl sm:text-3xl font-bold tracking-tight',
                    'text-sky-400' => $metrics['screening'] > 0,
                    'text-white' => $metrics['screening'] === 0,
                ])>{{ number_format($metrics['screening'], 0, ',', '.') }}</span>
            </div>
            <span class="mt-1 text-xs text-slate-400">Análise de perfil</span>
        </div>

        {{-- Em Entrevista --}}
        <div class="flex flex-col p-4 sm:p-5">
            <span class="text-[0.6875rem] font-semibold uppercase tracking-wider text-slate-400">Em Entrevista</span>
            <div class="mt-2 flex items-baseline gap-2">
                <span @class([
                    'font-mono text-2xl sm:text-3xl font-bold tracking-tight',
                    'text-indigo-300' => $metrics['interview'] > 0,
                    'text-white' => $metrics['interview'] === 0,
                ])>{{ number_format($metrics['interview'], 0, ',', '.') }}</span>
            </div>
            <span class="mt-1 text-xs text-slate-400">Rodadas ativas</span>
        </div>

        {{-- Finalistas --}}
        <div class="col-span-2 flex flex-col p-4 sm:col-span-1 sm:p-5">
            <span class="text-[0.6875rem] font-semibold uppercase tracking-wider text-slate-400">Finalistas</span>
            <div class="mt-2 flex items-baseline gap-2">
                <span @class([
                    'font-mono text-2xl sm:text-3xl font-bold tracking-tight',
                    'text-emerald-400' => $metrics['finalist'] > 0,
                    'text-white' => $metrics['finalist'] === 0,
                ])>{{ number_format($metrics['finalist'], 0, ',', '.') }}</span>
                @if ($metrics['finalist'] > 0)
                    <span class="inline-flex items-center rounded-full bg-emerald-500/15 px-2 py-0.5 text-[0.6875rem] font-medium text-emerald-300">Etapa Final</span>
                @endif
            </div>
            <span class="mt-1 text-xs text-slate-400">Etapa decisória</span>
        </div>
    </div>
</div>
