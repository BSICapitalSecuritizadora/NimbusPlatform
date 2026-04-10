@php
    $portalHealthMessage = match (true) {
        $emissionsCount === 0 && $documentsCount === 0 => 'Seu cadastro ainda não possui emissões ou documentos vinculados. Assim que a liberação operacional for concluída, o portal passará a exibir os conteúdos disponíveis.',
        $emissionsCount === 0 => 'Ainda não há emissões vinculadas ao seu cadastro. Se o vínculo já deveria estar ativo, vale acionar a equipe responsável para validar o escopo de acesso.',
        $documentsCount === 0 => 'As emissões já estão vinculadas, mas ainda não existem documentos publicados no seu escopo. O portal atualizará automaticamente quando novos arquivos forem liberados.',
        $newDocumentsCount === 0 => 'Não há novas publicações desde o seu último acesso. O ambiente segue pronto para consulta de documentos e acompanhamento das operações já disponíveis.',
        default => 'O portal está atualizado com emissões, documentos e novos arquivos publicados desde o último acesso.',
    };

    $portalHealthTone = match (true) {
        $emissionsCount === 0 || $documentsCount === 0 => 'border-amber-200 bg-amber-50/90 text-amber-800',
        $newDocumentsCount === 0 => 'border-brand-100 bg-brand-50/90 text-brand-800',
        default => 'border-emerald-200 bg-emerald-50/90 text-emerald-800',
    };
@endphp

<div class="space-y-8">
    <section class="grid gap-6 xl:grid-cols-[minmax(0,1.25fr)_22rem]">
        <div class="bsi-portal-panel p-8 lg:p-10">
            <div class="flex h-full flex-col gap-8">
                <div>
                    <div class="bsi-kicker mb-4 text-gold-400">Portal do investidor</div>
                    <h1 class="max-w-3xl text-4xl font-semibold tracking-[-0.05em] text-white md:text-5xl">
                        Bem-vindo, {{ $investor->name }}.
                    </h1>
                    <p class="mt-5 max-w-2xl text-base leading-8 text-brand-100">
                        Acesse suas emissões, acompanhe documentos publicados e concentre o relacionamento operacional em uma interface alinhada à identidade institucional da BSI Capital.
                    </p>
                </div>

                <div class="flex flex-wrap gap-3">
                    <flux:button variant="primary" as="a" href="{{ route('investor.documents') }}" class="!rounded-full !px-5 !py-3">
                        Ver documentos
                    </flux:button>
                    <flux:button variant="ghost" as="a" href="{{ route('investor.emissions') }}" class="!rounded-full !border !border-white/15 !bg-white/10 !px-5 !py-3 !text-white hover:!bg-white/15">
                        Minhas emissões
                    </flux:button>
                </div>

                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="bsi-portal-stat">
                        <div class="text-xs font-semibold uppercase tracking-[0.2em] text-gold-400">Emissões</div>
                        <div class="mt-3 text-3xl font-semibold tracking-[-0.04em] text-white">{{ number_format($emissionsCount) }}</div>
                        <p class="mt-2 text-sm leading-6 text-brand-100">Operações vinculadas ao seu cadastro.</p>
                    </div>
                    <div class="bsi-portal-stat">
                        <div class="text-xs font-semibold uppercase tracking-[0.2em] text-gold-400">Documentos</div>
                        <div class="mt-3 text-3xl font-semibold tracking-[-0.04em] text-white">{{ number_format($documentsCount) }}</div>
                        <p class="mt-2 text-sm leading-6 text-brand-100">Arquivos acessíveis dentro do seu escopo.</p>
                    </div>
                    <div class="bsi-portal-stat">
                        <div class="text-xs font-semibold uppercase tracking-[0.2em] text-gold-400">Último login</div>
                        <div class="mt-3 text-lg font-semibold text-white">
                            {{ $investor->last_login_at?->format('d/m/Y H:i') ?? 'Primeiro acesso' }}
                        </div>
                        <p class="mt-2 text-sm leading-6 text-brand-100">Registro de autenticação mais recente.</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-1">
            <div class="bsi-shell-card p-6">
                <div class="bsi-kicker mb-2">Perfil</div>
                <div class="text-2xl font-semibold tracking-[-0.04em] text-brand-800">Acesso institucional</div>
                <div class="mt-4 space-y-3 text-sm text-zinc-600">
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-[0.18em] text-zinc-400">E-mail</div>
                        <div class="mt-1 font-semibold text-zinc-800">{{ $investor->email }}</div>
                    </div>
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-[0.18em] text-zinc-400">Telefone</div>
                        <div class="mt-1 font-semibold text-zinc-800">{{ $investor->mobile ?: ($investor->phone ?: 'Não informado') }}</div>
                    </div>
                </div>
            </div>

            <div class="bsi-shell-card p-6">
                <div class="bsi-kicker mb-2">Governança</div>
                <div class="text-2xl font-semibold tracking-[-0.04em] text-brand-800">Documentos rastreáveis</div>
                <p class="mt-3 text-sm leading-7 text-zinc-600">
                    Consulte publicações e comunicações com a mesma lógica de controle documental aplicada ao site e às áreas operacionais da BSI.
                </p>
            </div>
        </div>
    </section>

    @if ($newDocumentsCount > 0)
        <section class="bsi-portal-surface p-6 lg:p-7">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex items-start gap-4">
                    <span class="mt-1 flex size-12 items-center justify-center rounded-2xl bg-brand-50 text-brand-700">
                        <flux:icon.document-text class="size-6" />
                    </span>
                    <div>
                        <h2 class="text-xl font-semibold tracking-[-0.03em] text-brand-800">Novos documentos disponíveis</h2>
                        <p class="mt-2 max-w-2xl text-sm leading-7 text-zinc-600">
                            Você tem <strong>{{ $newDocumentsCount }}</strong> documento(s) publicado(s) desde o seu último acesso ao portal.
                        </p>
                    </div>
                </div>

                <flux:button variant="primary" as="a" href="{{ route('investor.documents') }}" class="!rounded-full !px-5">
                    Ver documentos
                </flux:button>
            </div>
        </section>
    @endif

    <section class="grid gap-4 lg:grid-cols-3">
        <article class="bsi-shell-card p-6">
            <div class="flex items-start gap-4">
                <span class="flex size-12 items-center justify-center rounded-2xl bg-brand-50 text-brand-700">
                    <flux:icon.chart-pie class="size-6" />
                </span>
                <div>
                    <div class="bsi-kicker mb-2">Status do acesso</div>
                    <h2 class="text-2xl font-semibold tracking-[-0.04em] text-brand-800">Cobertura operacional</h2>
                    <p class="mt-3 text-sm leading-7 text-zinc-600">
                        {{ $emissionsCount > 0 ? 'Seu cadastro está associado a '.$emissionsCount.' operação(ões) com acompanhamento centralizado no portal.' : 'Nenhuma operação está disponível neste momento para consulta no portal.' }}
                    </p>
                </div>
            </div>
        </article>

        <article class="bsi-shell-card p-6">
            <div class="flex items-start gap-4">
                <span class="flex size-12 items-center justify-center rounded-2xl bg-brand-50 text-brand-700">
                    <flux:icon.folder-open class="size-6" />
                </span>
                <div>
                    <div class="bsi-kicker mb-2">Consulta documental</div>
                    <h2 class="text-2xl font-semibold tracking-[-0.04em] text-brand-800">Disponibilidade de arquivos</h2>
                    <p class="mt-3 text-sm leading-7 text-zinc-600">
                        {{ $documentsCount > 0 ? 'Há '.$documentsCount.' documento(s) acessível(is) dentro do seu escopo atual, com filtros por emissão, categoria e período.' : 'Ainda não existem documentos liberados para o seu perfil de acesso.' }}
                    </p>
                </div>
            </div>
        </article>

        <article class="rounded-[26px] border px-6 py-5 shadow-sm {{ $portalHealthTone }}">
            <div class="flex items-start gap-4">
                <span class="flex size-12 items-center justify-center rounded-2xl bg-white/70">
                    <flux:icon.shield-check class="size-6" />
                </span>
                <div>
                    <div class="text-xs font-semibold uppercase tracking-[0.2em]">Leitura do portal</div>
                    <p class="mt-3 text-sm leading-7">
                        {{ $portalHealthMessage }}
                    </p>
                </div>
            </div>
        </article>
    </section>

    <section class="grid gap-4 lg:grid-cols-2">
        <article class="bsi-shell-card p-6">
            <div class="flex items-start gap-4">
                <span class="flex size-12 items-center justify-center rounded-2xl bg-brand-50 text-brand-700">
                    <flux:icon.chart-bar class="size-6" />
                </span>
                <div>
                    <div class="bsi-kicker mb-2">Acesso rápido</div>
                    <h2 class="text-2xl font-semibold tracking-[-0.04em] text-brand-800">Minhas emissões</h2>
                    <p class="mt-3 text-sm leading-7 text-zinc-600">
                        Consulte operações associadas ao seu cadastro com status, vencimento e informações essenciais para acompanhamento.
                    </p>
                    <flux:button variant="subtle" as="a" href="{{ route('investor.emissions') }}" class="mt-4 !rounded-full !px-5">
                        Abrir emissões
                    </flux:button>
                </div>
            </div>
        </article>

        <article class="bsi-shell-card p-6">
            <div class="flex items-start gap-4">
                <span class="flex size-12 items-center justify-center rounded-2xl bg-brand-50 text-brand-700">
                    <flux:icon.folder class="size-6" />
                </span>
                <div>
                    <div class="bsi-kicker mb-2">Consulta documental</div>
                    <h2 class="text-2xl font-semibold tracking-[-0.04em] text-brand-800">Documentos e relatórios</h2>
                    <p class="mt-3 text-sm leading-7 text-zinc-600">
                        Filtre por emissão, categoria ou período e mantenha uma rotina de consulta alinhada ao padrão institucional do portal.
                    </p>
                    <flux:button variant="subtle" as="a" href="{{ route('investor.documents') }}" class="mt-4 !rounded-full !px-5">
                        Abrir documentos
                    </flux:button>
                </div>
            </div>
        </article>
    </section>
</div>
