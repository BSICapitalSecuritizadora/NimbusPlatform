@props([
    'sidebar' => false,
])

@if($sidebar)
    <flux:sidebar.brand name="BSI Capital" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-9 items-center justify-center rounded-2xl bg-[linear-gradient(135deg,#091b23,#22424c)] text-white shadow-[0_14px_28px_rgba(9,27,35,0.25)]">
            <x-app-logo-icon class="size-5 fill-current text-white" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand name="BSI Capital" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-9 items-center justify-center rounded-2xl bg-[linear-gradient(135deg,#091b23,#22424c)] text-white shadow-[0_14px_28px_rgba(9,27,35,0.25)]">
            <x-app-logo-icon class="size-5 fill-current text-white" />
        </x-slot>
    </flux:brand>
@endif
