@extends('investor.layout')

@section('title', 'Login - Portal do Investidor')

@section('content')
<section class="grid gap-6 xl:grid-cols-[minmax(0,1.08fr)_28rem] lg:items-stretch">
    <div class="bsi-portal-panel p-8 lg:p-10">
        <div class="flex h-full flex-col gap-8">
            <div class="flex flex-wrap gap-2">
                <span class="bsi-investor-chip">Documentos publicados</span>
                <span class="bsi-investor-chip">Emissões vinculadas</span>
                <span class="bsi-investor-chip">Comunicados oficiais</span>
            </div>

            <div>
                <div class="bsi-kicker mb-4 text-gold-400">Relacionamento com investidores</div>
                <h1 class="max-w-3xl text-4xl font-semibold tracking-[-0.05em] text-white md:text-5xl">
                    Documentos, emissões e comunicação institucional em um único acesso.
                </h1>
                <p class="mt-5 max-w-2xl text-base leading-8 text-brand-100">
                    O portal do investidor organiza publicações, emissões vinculadas ao seu cadastro e rotinas de consulta com a mesma linguagem visual e o mesmo rigor institucional do site da BSI Capital.
                </p>
            </div>

            <div class="space-y-4">
                <div class="flex flex-col gap-3 border-t border-white/10 pt-6 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-[0.2em] text-white/55">Estrutura do portal</div>
                        <div class="mt-2 text-2xl font-semibold tracking-[-0.04em] text-white">Consulta desenhada para leitura objetiva</div>
                    </div>
                    <p class="max-w-xl text-sm leading-7 text-brand-100/85">
                        A navegação prioriza o que importa para o acompanhamento do investidor: documentos, emissões e uma camada institucional clara.
                    </p>
                </div>

                <div class="grid gap-4 md:grid-cols-3">
                    <div class="bsi-portal-stat p-5">
                        <span class="flex size-11 items-center justify-center rounded-2xl bg-white/10 text-gold-400">
                            <flux:icon.document-text class="size-5" />
                        </span>
                        <div class="mt-4 text-xs font-semibold uppercase tracking-[0.22em] text-gold-400">Consulta</div>
                        <div class="mt-2 text-2xl font-semibold tracking-[-0.04em] text-white">Documentos</div>
                        <p class="mt-2 text-sm leading-6 text-brand-100">Arquivos e relatórios organizados para leitura rápida e download controlado.</p>
                    </div>
                    <div class="bsi-portal-stat p-5">
                        <span class="flex size-11 items-center justify-center rounded-2xl bg-white/10 text-gold-400">
                            <flux:icon.chart-bar class="size-5" />
                        </span>
                        <div class="mt-4 text-xs font-semibold uppercase tracking-[0.22em] text-gold-400">Acompanhamento</div>
                        <div class="mt-2 text-2xl font-semibold tracking-[-0.04em] text-white">Emissões</div>
                        <p class="mt-2 text-sm leading-6 text-brand-100">Visão centralizada das operações relacionadas ao seu escopo de investimento.</p>
                    </div>
                    <div class="bsi-portal-stat p-5">
                        <span class="flex size-11 items-center justify-center rounded-2xl bg-white/10 text-gold-400">
                            <flux:icon.shield-check class="size-5" />
                        </span>
                        <div class="mt-4 text-xs font-semibold uppercase tracking-[0.22em] text-gold-400">Governança</div>
                        <div class="mt-2 text-2xl font-semibold tracking-[-0.04em] text-white">Rastreio</div>
                        <p class="mt-2 text-sm leading-6 text-brand-100">Acesso individual, consistência visual e leitura documental alinhada à BSI.</p>
                    </div>
                </div>
            </div>

            <div class="rounded-[28px] border border-white/10 bg-[linear-gradient(180deg,rgba(255,255,255,0.08),rgba(255,255,255,0.04))] p-6">
                <div class="grid gap-5 lg:grid-cols-[minmax(0,1.05fr)_minmax(0,0.95fr)] lg:items-start">
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-[0.22em] text-gold-400">Leitura institucional</div>
                        <h2 class="mt-3 max-w-xl text-2xl font-semibold tracking-[-0.04em] text-white">
                            A área privada fala a mesma língua visual e documental do site institucional.
                        </h2>
                        <p class="mt-3 max-w-2xl text-sm leading-7 text-brand-100">
                            O desenho da interface preserva a sobriedade da marca, mas simplifica a rotina de quem precisa localizar documentos, acompanhar emissões e consultar publicações com continuidade operacional.
                        </p>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-1">
                        <div class="bsi-portal-panel-soft p-4">
                            <div class="flex items-start gap-3">
                                <span class="mt-1 flex size-10 items-center justify-center rounded-2xl bg-white/10 text-gold-400">
                                    <flux:icon.document-text class="size-5" />
                                </span>
                                <div>
                                    <div class="text-sm font-semibold text-white">Camada institucional</div>
                                    <p class="mt-2 text-sm leading-6 text-brand-100">Consulta objetiva sem perder o padrão visual e editorial da BSI.</p>
                                </div>
                            </div>
                        </div>

                        <div class="bsi-portal-panel-soft p-4">
                            <div class="flex items-start gap-3">
                                <span class="mt-1 flex size-10 items-center justify-center rounded-2xl bg-white/10 text-gold-400">
                                    <flux:icon.shield-check class="size-5" />
                                </span>
                                <div>
                                    <div class="text-sm font-semibold text-white">Ambiente protegido</div>
                                    <p class="mt-2 text-sm leading-6 text-brand-100">Credenciais individuais, rastreio e continuidade operacional para a consulta.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="bsi-investor-form-card p-8 lg:p-10">
        <div class="mb-8 space-y-4">
            <div class="inline-flex items-center gap-2 rounded-full border border-brand-100 bg-brand-50/85 px-3 py-2 text-xs font-semibold uppercase tracking-[0.18em] text-brand-700">
                <flux:icon.lock-closed class="size-4 text-gold-500" />
                <span>Acesso institucional</span>
            </div>

            <div>
                <div class="bsi-kicker mb-2">Autenticação</div>
                <h2 class="text-3xl font-semibold tracking-[-0.04em] text-brand-800">Entrar no portal</h2>
                <p class="mt-3 text-sm leading-7 text-zinc-600">
                    Informe suas credenciais para acessar documentos, emissões e comunicações do seu relacionamento com investidores.
                </p>
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
                <div class="rounded-[20px] border border-brand-100 bg-brand-50/70 px-4 py-3">
                    <div class="text-[0.68rem] font-semibold uppercase tracking-[0.18em] text-zinc-400">Escopo</div>
                    <div class="mt-1 text-sm font-semibold text-brand-800">Documentos e emissões</div>
                </div>
                <div class="rounded-[20px] border border-brand-100 bg-white px-4 py-3">
                    <div class="text-[0.68rem] font-semibold uppercase tracking-[0.18em] text-zinc-400">Padrão</div>
                    <div class="mt-1 text-sm font-semibold text-brand-800">Identidade BSI Capital</div>
                </div>
            </div>
        </div>

        @if ($errors->any())
            <div class="mb-6 rounded-[22px] border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('investor.login.post') }}" class="space-y-5">
            @csrf

            <flux:input
                class="!rounded-[18px]"
                name="email"
                type="email"
                label="E-mail"
                value="{{ old('email') }}"
                placeholder="email@empresa.com.br"
                required
                autofocus
                autocomplete="email"
            />

            <flux:input
                class="!rounded-[18px]"
                name="password"
                type="password"
                label="Senha"
                placeholder="Informe sua senha"
                viewable
                required
                autocomplete="current-password"
            />

            <div class="flex flex-col gap-3 rounded-[22px] border border-zinc-200/80 bg-zinc-50/80 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                <flux:checkbox name="remember" label="Manter conectado" :checked="old('remember')" />
                <div class="inline-flex items-center gap-2 text-xs font-medium uppercase tracking-[0.16em] text-zinc-500">
                    <flux:icon.lock-closed class="size-4 text-gold-500" />
                    <span>Conexão protegida</span>
                </div>
            </div>

            <flux:button variant="primary" type="submit" class="w-full !rounded-full !bg-brand-700 !py-3.5 hover:!bg-brand-800">
                Entrar
            </flux:button>
        </form>

        <div class="mt-8 rounded-[24px] border border-brand-100 bg-[linear-gradient(180deg,rgba(238,244,255,0.95),rgba(255,255,255,0.92))] p-5">
            <div class="text-xs font-semibold uppercase tracking-[0.18em] text-gold-500">Canal institucional</div>
            <p class="mt-3 text-sm leading-7 text-zinc-600">
                Se precisar de ajuda com acesso, atualização cadastral ou validação do seu escopo, fale com a equipe da BSI Capital.
            </p>
            <a href="mailto:contato@bsicapital.com.br" class="mt-3 inline-flex items-center gap-2 text-sm font-semibold text-brand-700 transition hover:text-brand-800">
                contato@bsicapital.com.br
                <flux:icon.arrow-up-right class="size-4" />
            </a>
        </div>
    </div>
</section>
@endsection
