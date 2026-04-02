<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:header container class="border-b border-zinc-200/70 bg-white/85 backdrop-blur dark:border-white/10 dark:bg-[#08111dcc]">
            <flux:sidebar.toggle class="mr-2 lg:hidden" icon="bars-2" inset="left" />

            <x-app-logo href="{{ route('dashboard') }}" wire:navigate />

            <div class="ms-4 hidden items-center gap-3 lg:flex">
                <span class="rounded-full border border-gold-500/30 bg-gold-500/10 px-3 py-1 text-[0.68rem] font-bold uppercase tracking-[0.24em] text-gold-500">
                    Ambiente interno
                </span>
                <span class="text-sm text-zinc-500 dark:text-zinc-400">
                    Operação, documentos e relacionamento no padrão BSI Capital.
                </span>
            </div>

            <flux:spacer />

            <flux:navbar class="-mb-px hidden items-center gap-1 py-0! lg:flex">
                <flux:navbar.item icon="layout-grid" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                    {{ __('Dashboard') }}
                </flux:navbar.item>
                <flux:navbar.item icon="globe-alt" :href="route('site.home')">
                    {{ __('Site') }}
                </flux:navbar.item>
                <flux:navbar.item icon="document-text" :href="route('proposal.create')">
                    {{ __('Nova proposta') }}
                </flux:navbar.item>
            </flux:navbar>

            <div class="ms-2 hidden items-center gap-2 lg:flex">
                <a href="{{ route('site.ri') }}" class="bsi-action-secondary !px-4 !py-2 text-sm">
                    {{ __('R.I.') }}
                </a>
            </div>

            <x-desktop-user-menu />
        </flux:header>

        <flux:sidebar collapsible="mobile" sticky class="border-e border-zinc-200/70 bg-white/95 dark:border-white/10 dark:bg-[#08111df2] lg:hidden">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="in-data-flux-sidebar-on-desktop:not-in-data-flux-sidebar-collapsed-desktop:-mr-2" />
            </flux:sidebar.header>

            <div class="px-4 pb-2">
                <div class="bsi-kicker mb-2">Navegação</div>
                <p class="bsi-copy text-xs">
                    Acesso rápido aos principais pontos do ambiente interno e do site institucional.
                </p>
            </div>

            <flux:sidebar.nav>
                <flux:sidebar.group :heading="__('Painel')">
                    <flux:sidebar.item icon="layout-grid" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                        {{ __('Dashboard') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="globe-alt" :href="route('site.home')">
                        {{ __('Site') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="document-text" :href="route('proposal.create')">
                        {{ __('Nova proposta') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="cog" :href="route('profile.edit')" wire:navigate>
                        {{ __('Configurações') }}
                    </flux:sidebar.item>
                </flux:sidebar.group>
            </flux:sidebar.nav>
        </flux:sidebar>

        {{ $slot }}

        @fluxScripts
    </body>
</html>
