<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200/70 bg-white/95 dark:border-white/10 dark:bg-[#08111df2]">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <div class="px-4 pb-4">
                <div class="bsi-kicker mb-2">Painel BSI</div>
                <p class="bsi-copy text-xs">
                    Ambiente interno para leitura operacional, acesso institucional e navegação rápida entre fluxos relevantes.
                </p>
            </div>

            <flux:sidebar.nav>
                <flux:sidebar.group :heading="__('Navegação')" class="grid">
                    <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                        {{ __('Visão geral') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="globe-alt" :href="route('site.home')">
                        {{ __('Site institucional') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="document-text" :href="route('site.proposal.create')">
                        {{ __('Nova proposta') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="folder-open" :href="route('site.ri')">
                        {{ __('Relações com investidores') }}
                    </flux:sidebar.item>
                </flux:sidebar.group>
            </flux:sidebar.nav>

            <flux:spacer />

            <div class="px-4 pb-4">
                <div class="bsi-shell-card-soft p-4">
                    <div class="text-xs font-semibold uppercase tracking-[0.2em] text-gold-500">{{ __('Conta') }}</div>
                    <div class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">
                        Gerencie preferências, perfil e acesso ao ambiente interno da BSI Capital.
                    </div>
                </div>
            </div>

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <flux:header class="border-b border-zinc-200/70 bg-white/90 backdrop-blur dark:border-white/10 dark:bg-[#08111dcc] lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />
            <flux:spacer />
            <div class="text-sm font-semibold tracking-[-0.02em] text-brand-700 dark:text-white">{{ __('Painel BSI Capital') }}</div>
        </flux:header>

        {{ $slot }}

        @fluxScripts
    </body>
</html>
