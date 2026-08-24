@php
    $user = auth()->user();
    $avatarUrl = $user?->avatarUrl();
    $displayRole = $user?->cargo ?: ($user?->hasRole(['super-admin', 'admin']) ? __('Administrador') : ($user?->hasRole('editor') ? __('Operações') : ($user?->hasRole('commercial-representative') ? __('Comercial') : __('BSI Capital'))));
@endphp

<flux:dropdown position="bottom" align="start">
    <flux:sidebar.profile
        :name="$user?->name ?? ''"
        :initials="$user?->initials() ?? ''"
        :avatar="$avatarUrl"
        circle
        icon:trailing="chevrons-up-down"
        data-test="sidebar-menu-button"
    />

    <flux:menu>
        <a href="{{ route('profile.edit') }}" wire:navigate class="flex items-center gap-3 rounded-lg px-1 py-1.5 text-start transition hover:bg-zinc-50 dark:hover:bg-white/5">
            <flux:avatar
                :name="$user?->name ?? ''"
                :initials="$user?->initials() ?? ''"
                :src="$avatarUrl"
                circle
                size="sm"
            />
            <div class="grid flex-1 text-start text-sm leading-tight">
                <flux:heading class="truncate">{{ $user?->name }}</flux:heading>
                <flux:text class="truncate text-xs">{{ $displayRole }}</flux:text>
                <flux:text class="truncate text-xs opacity-60">{{ $user?->email }}</flux:text>
            </div>
        </a>
        <flux:menu.separator />
        <flux:menu.radio.group>
            <flux:menu.item :href="route('profile.edit')" icon="user" wire:navigate>
                {{ __('Meu Perfil') }}
            </flux:menu.item>
            <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                {{ __('Configurações') }}
            </flux:menu.item>
            <form method="POST" action="{{ route('logout') }}" class="w-full">
                @csrf
                <flux:menu.item
                    as="button"
                    type="submit"
                    icon="arrow-right-start-on-rectangle"
                    class="w-full cursor-pointer"
                    data-test="logout-button"
                >
                    {{ __('Sair') }}
                </flux:menu.item>
            </form>
        </flux:menu.radio.group>
    </flux:menu>
</flux:dropdown>
