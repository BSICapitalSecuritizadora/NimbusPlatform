<x-filament-panels::page>
    <div class="mx-auto w-full max-w-6xl space-y-8 pb-10">
        @php
            $hasUsers = $this->canAccessUsers();
            $hasRoles = $this->canAccessRoles();
            $hasTemplates = $this->canAccessSpreadsheetTemplates();
            $hasAccessSection = $hasUsers || $hasRoles;
            $hasOperationSection = $hasTemplates;
        @endphp

        {{-- Bloco 1: Acesso e Segurança --}}
        @if ($hasAccessSection)
            <section class="space-y-4">
                <div class="space-y-1">
                    <h2 class="text-base font-bold tracking-tight text-white sm:text-lg">
                        Acesso e Segurança
                    </h2>
                    <p class="text-xs text-slate-400 sm:text-sm">
                        Gerencie usuários, perfis e permissões administrativas.
                    </p>
                </div>

                <div class="grid grid-cols-1 gap-5 md:grid-cols-2 items-stretch">
                    {{-- Card Usuários --}}
                    @if ($hasUsers)
                        <a
                            href="{{ $this->getUsersUrl() }}"
                            aria-label="Acessar gerenciamento de Usuários"
                            class="group relative flex h-full flex-col justify-between rounded-2xl border border-slate-700/50 bg-[#0d2530] p-6 shadow-sm transition duration-150 hover:border-[#a06e28]/50 hover:bg-[#12313b] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#a06e28] focus-visible:ring-offset-2 focus-visible:ring-offset-[#091b23]"
                        >
                            <div class="space-y-4">
                                <div class="flex h-11 w-11 items-center justify-center rounded-xl border border-slate-700/60 bg-[#091b23] text-primary-400 transition-colors group-hover:border-[#a06e28]/40 group-hover:text-primary-300">
                                    <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedUsers" class="h-6 w-6" />
                                </div>
                                <div class="space-y-1.5">
                                    <h3 class="text-base font-bold text-white transition-colors group-hover:text-[#e6e4e4]">
                                        Usuários
                                    </h3>
                                    <p class="text-xs leading-relaxed text-slate-300/90 sm:text-sm">
                                        Gerencie os usuários administrativos, acessos e status no sistema.
                                    </p>
                                </div>
                            </div>

                            <div class="mt-6 flex items-center gap-1.5 pt-2 text-xs font-semibold text-primary-400 transition-colors group-hover:text-[#d49e47] sm:text-sm">
                                <span>Gerenciar</span>
                                <span class="transition-transform group-hover:translate-x-0.5">→</span>
                            </div>
                        </a>
                    @endif

                    {{-- Card Perfis de Acesso --}}
                    @if ($hasRoles)
                        <a
                            href="{{ $this->getRolesUrl() }}"
                            aria-label="Acessar gerenciamento de Perfis de acesso"
                            class="group relative flex h-full flex-col justify-between rounded-2xl border border-slate-700/50 bg-[#0d2530] p-6 shadow-sm transition duration-150 hover:border-[#a06e28]/50 hover:bg-[#12313b] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#a06e28] focus-visible:ring-offset-2 focus-visible:ring-offset-[#091b23]"
                        >
                            <div class="space-y-4">
                                <div class="flex h-11 w-11 items-center justify-center rounded-xl border border-slate-700/60 bg-[#091b23] text-primary-400 transition-colors group-hover:border-[#a06e28]/40 group-hover:text-primary-300">
                                    <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedShieldCheck" class="h-6 w-6" />
                                </div>
                                <div class="space-y-1.5">
                                    <h3 class="text-base font-bold text-white transition-colors group-hover:text-[#e6e4e4]">
                                        Perfis de acesso
                                    </h3>
                                    <p class="text-xs leading-relaxed text-slate-300/90 sm:text-sm">
                                        Configure papéis e permissões disponíveis para os usuários.
                                    </p>
                                </div>
                            </div>

                            <div class="mt-6 flex items-center gap-1.5 pt-2 text-xs font-semibold text-primary-400 transition-colors group-hover:text-[#d49e47] sm:text-sm">
                                <span>Gerenciar</span>
                                <span class="transition-transform group-hover:translate-x-0.5">→</span>
                            </div>
                        </a>
                    @endif
                </div>
            </section>
        @endif

        {{-- Bloco 2: Operação e Templates --}}
        @if ($hasOperationSection)
            <section class="space-y-4">
                <div class="space-y-1">
                    <h2 class="text-base font-bold tracking-tight text-white sm:text-lg">
                        Operação e Templates
                    </h2>
                    <p class="text-xs text-slate-400 sm:text-sm">
                        Configure recursos utilizados pelas rotinas operacionais.
                    </p>
                </div>

                <div class="grid grid-cols-1 gap-5 md:grid-cols-2 items-stretch">
                    {{-- Card Templates de Planilhas --}}
                    @if ($hasTemplates)
                        <a
                            href="{{ $this->getSpreadsheetTemplatesUrl() }}"
                            aria-label="Acessar configuração de Templates de Planilhas"
                            class="group relative flex h-full flex-col justify-between rounded-2xl border border-slate-700/50 bg-[#0d2530] p-6 shadow-sm transition duration-150 hover:border-[#a06e28]/50 hover:bg-[#12313b] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#a06e28] focus-visible:ring-offset-2 focus-visible:ring-offset-[#091b23]"
                        >
                            <div class="space-y-4">
                                <div class="flex h-11 w-11 items-center justify-center rounded-xl border border-slate-700/60 bg-[#091b23] text-primary-400 transition-colors group-hover:border-[#a06e28]/40 group-hover:text-primary-300">
                                    <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedDocumentDuplicate" class="h-6 w-6" />
                                </div>
                                <div class="space-y-1.5">
                                    <h3 class="text-base font-bold text-white transition-colors group-hover:text-[#e6e4e4]">
                                        Templates de Planilhas
                                    </h3>
                                    <p class="text-xs leading-relaxed text-slate-300/90 sm:text-sm">
                                        Gerencie os modelos usados em pagamentos, PU e integralizações.
                                    </p>
                                </div>
                            </div>

                            <div class="mt-6 flex items-center gap-1.5 pt-2 text-xs font-semibold text-primary-400 transition-colors group-hover:text-[#d49e47] sm:text-sm">
                                <span>Configurar</span>
                                <span class="transition-transform group-hover:translate-x-0.5">→</span>
                            </div>
                        </a>
                    @endif
                </div>
            </section>
        @endif

        {{-- Empty State (caso usuário não tenha permissão em nenhuma subseção) --}}
        @if (! $hasAccessSection && ! $hasOperationSection)
            <div class="flex flex-col items-center justify-center rounded-2xl border border-slate-800/80 bg-[#0d2530] p-12 text-center">
                <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedLockClosed" class="h-10 w-10 text-slate-500 mb-3" />
                <h3 class="text-base font-bold text-white">Nenhuma configuração disponível</h3>
                <p class="mt-1 max-w-sm text-xs text-slate-400">
                    Seu perfil não possui permissões para gerenciar as seções administrativas disponíveis nesta central.
                </p>
            </div>
        @endif
    </div>
</x-filament-panels::page>
