@extends('investor.layout')

@section('title', 'Login - Portal do Investidor')

@section('content')
<section class="grid gap-6 lg:grid-cols-[minmax(0,1.1fr)_26rem] lg:items-stretch">
    <div class="bsi-portal-panel p-8 lg:p-10">
        <div class="flex h-full flex-col gap-8">
            <div>
                <div class="bsi-kicker mb-4 text-gold-400">Relacionamento com investidores</div>
                <h1 class="max-w-3xl text-4xl font-semibold tracking-[-0.05em] text-white md:text-5xl">
                    Acompanhe emissões, documentos e eventos do seu investimento em um ambiente único.
                </h1>
                <p class="mt-5 max-w-2xl text-base leading-8 text-brand-100">
                    O portal concentra documentos publicados, emissões vinculadas ao seu cadastro e comunicações operacionais com a mesma linguagem institucional do site da BSI Capital.
                </p>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <div class="bsi-portal-stat">
                    <div class="text-xs font-semibold uppercase tracking-[0.22em] text-gold-400">Clareza</div>
                    <div class="mt-3 text-2xl font-semibold tracking-[-0.04em] text-white">Portal</div>
                    <p class="mt-2 text-sm leading-6 text-brand-100">Acesso centralizado a emissões, documentos e atualizações.</p>
                </div>
                <div class="bsi-portal-stat">
                    <div class="text-xs font-semibold uppercase tracking-[0.22em] text-gold-400">Segurança</div>
                    <div class="mt-3 text-2xl font-semibold tracking-[-0.04em] text-white">Controle</div>
                    <p class="mt-2 text-sm leading-6 text-brand-100">Credenciais individuais e rastreabilidade de acesso.</p>
                </div>
                <div class="bsi-portal-stat">
                    <div class="text-xs font-semibold uppercase tracking-[0.22em] text-gold-400">Suporte</div>
                    <div class="mt-3 text-2xl font-semibold tracking-[-0.04em] text-white">Equipe</div>
                    <p class="mt-2 text-sm leading-6 text-brand-100">Atendimento alinhado ao padrão institucional da BSI.</p>
                </div>
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
                <div class="rounded-[24px] border border-white/10 bg-white/10 p-5 backdrop-blur">
                    <div class="flex items-start gap-3">
                        <span class="mt-1 flex size-11 items-center justify-center rounded-2xl bg-white/10 text-gold-400">
                            <flux:icon.document-text class="size-5" />
                        </span>
                        <div>
                            <div class="text-sm font-semibold text-white">Documentos publicados</div>
                            <p class="mt-2 text-sm leading-6 text-brand-100">Baixe arquivos vinculados às suas emissões sem sair do ambiente autenticado.</p>
                        </div>
                    </div>
                </div>
                <div class="rounded-[24px] border border-white/10 bg-white/10 p-5 backdrop-blur">
                    <div class="flex items-start gap-3">
                        <span class="mt-1 flex size-11 items-center justify-center rounded-2xl bg-white/10 text-gold-400">
                            <flux:icon.shield-check class="size-5" />
                        </span>
                        <div>
                            <div class="text-sm font-semibold text-white">Ambiente protegido</div>
                            <p class="mt-2 text-sm leading-6 text-brand-100">A experiência foi desenhada para transmitir segurança e consistência com o restante da marca.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="bsi-shell-card p-8 lg:p-10">
        <div class="mb-8">
            <div class="bsi-kicker mb-2">Autenticação</div>
            <h2 class="text-3xl font-semibold tracking-[-0.04em] text-brand-800">Entrar no portal</h2>
            <p class="mt-3 text-sm leading-7 text-zinc-600">
                Informe suas credenciais para acessar o ambiente do investidor com segurança e continuidade.
            </p>
        </div>

        @if ($errors->any())
            <div class="mb-6 rounded-[22px] border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('investor.login.post') }}" class="space-y-5">
            @csrf

            <flux:input
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
                name="password"
                type="password"
                label="Senha"
                placeholder="Informe sua senha"
                viewable
                required
                autocomplete="current-password"
            />

            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <flux:checkbox name="remember" label="Manter conectado" :checked="old('remember')" />
                <div class="inline-flex items-center gap-2 text-xs font-medium uppercase tracking-[0.16em] text-zinc-500">
                    <flux:icon.lock-closed class="size-4 text-gold-500" />
                    <span>Conexão protegida</span>
                </div>
            </div>

            <flux:button variant="primary" type="submit" class="w-full !rounded-full !py-3">
                Entrar
            </flux:button>
        </form>

        <div class="mt-8 rounded-[24px] border border-brand-100 bg-brand-50/70 p-5">
            <div class="text-xs font-semibold uppercase tracking-[0.18em] text-gold-500">Suporte institucional</div>
            <p class="mt-3 text-sm leading-7 text-zinc-600">
                Se precisar de ajuda com acesso ou atualização cadastral, fale com a equipe da BSI Capital.
            </p>
            <a href="mailto:contato@bsicapital.com.br" class="mt-3 inline-flex items-center gap-2 text-sm font-semibold text-brand-700 transition hover:text-brand-800">
                contato@bsicapital.com.br
                <flux:icon.arrow-up-right class="size-4" />
            </a>
        </div>
    </div>
</section>
@endsection
