@php
    $user = filament()->auth()->user();

    $userContext = match (true) {
        filled($user?->cargo) => $user->cargo,
        $user?->hasRole(['super-admin', 'admin']) => __('Administrador'),
        $user?->hasRole('editor') => __('Operações'),
        $user?->hasRole('commercial-representative') => __('Comercial'),
        default => __('BSI Capital'),
    };
@endphp

@if ($user)
    <div class="bsi-topbar-user-context" title="{{ filament()->getUserName($user) }} — {{ $userContext }}">
        <span class="bsi-topbar-user-name">{{ filament()->getUserName($user) }}</span>
        <span class="bsi-topbar-user-role">{{ $userContext }}</span>
    </div>
@endif
