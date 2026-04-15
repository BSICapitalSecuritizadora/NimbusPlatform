@extends('investor.layout')

@section('title', 'Login - Portal do Investidor')

@push('head')
<style>
    .bsi-investor-login-form [data-flux-field] {
        gap: 0.65rem;
    }

    .bsi-investor-login-form [data-flux-label] {
        display: block;
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 0.14em;
        text-transform: uppercase;
        color: #00205b;
    }

    .bsi-investor-login-form .bsi-investor-credential-field {
        gap: 0.45rem;
        padding: 0.95rem 1rem;
        border: 2px solid var(--color-brand-700);
        border-radius: 1rem;
        background: #fff;
    }

    .bsi-investor-login-form .bsi-investor-credential-field:focus-within {
        border-color: var(--color-brand-700);
        box-shadow: 0 0 0 3px rgba(0, 32, 91, 0.12);
    }

    .bsi-investor-login-form .bsi-investor-credential-field input[data-flux-control]:not([type='checkbox']) {
        min-height: auto;
        border: 0 !important;
        background: transparent !important;
        color: #0a1734 !important;
        padding-inline: 0;
        box-shadow: none !important;
    }

    .bsi-investor-login-form .bsi-investor-credential-field input[data-flux-control]::placeholder {
        color: #94a3b8;
        opacity: 1;
    }
</style>
@endpush

@section('content')
<section class="mx-auto w-full max-w-[34rem]">
    <div class="bsi-investor-form-card p-8 lg:p-10">
        <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <img src="{{ asset('images/logo-mob.png') }}" alt="BSI Capital" class="h-11 w-auto">
            <a href="{{ route('site.home') }}" class="inline-flex items-center justify-center rounded-full border border-brand-100 bg-brand-50/80 px-4 py-2.5 text-sm font-semibold text-brand-700 transition hover:border-gold-500/50 hover:bg-white">
                Voltar ao site
            </a>
        </div>

        @if ($errors->any())
            <div class="mb-6 rounded-[22px] border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('investor.login.post') }}" class="bsi-investor-login-form space-y-5">
            @csrf

            <div class="space-y-4">
                <flux:field class="bsi-investor-credential-field">
                    <flux:label>E-mail</flux:label>
                    <flux:input
                        class="!rounded-[18px]"
                        name="email"
                        type="email"
                        value="{{ old('email') }}"
                        placeholder="email@empresa.com.br"
                        required
                        autofocus
                        autocomplete="email"
                    />
                </flux:field>

                <flux:field class="bsi-investor-credential-field">
                    <flux:label>Senha</flux:label>
                    <flux:input
                        class="!rounded-[18px]"
                        name="password"
                        type="password"
                        placeholder="Informe sua senha"
                        viewable
                        required
                        autocomplete="current-password"
                    />
                </flux:field>
            </div>

            <div class="flex flex-col gap-3 rounded-[22px] border border-zinc-200/80 bg-zinc-50/80 px-4 py-3.5 sm:flex-row sm:items-center sm:justify-between">
                <label class="group flex cursor-pointer items-center gap-3">
                    <div class="relative flex items-center justify-center">
                        <input type="checkbox" name="remember" class="peer size-5 cursor-pointer appearance-none rounded-md border border-zinc-300 bg-white transition hover:border-brand-500 checked:border-brand-700 checked:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-brand-500/20" {{ old('remember') ? 'checked' : '' }}>
                        <svg class="pointer-events-none absolute size-3.5 text-white opacity-0 transition peer-checked:opacity-100" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="20 6 9 17 4 12"></polyline>
                        </svg>
                    </div>
                    <span class="text-[0.95rem] font-medium text-zinc-700 transition group-hover:text-zinc-900">Manter conectado</span>
                </label>
                <div class="inline-flex items-center gap-2 text-xs font-medium uppercase tracking-[0.16em] text-zinc-500">
                    <flux:icon.lock-closed class="size-4 text-gold-500" />
                    <span>Conexão protegida</span>
                </div>
            </div>

            <button type="submit" class="mt-2 w-full rounded-full bg-brand-700 py-3.5 text-[0.95rem] font-semibold text-white shadow-sm transition hover:bg-brand-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-700">
                Entrar
            </button>
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