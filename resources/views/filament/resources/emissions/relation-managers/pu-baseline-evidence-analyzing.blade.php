{{-- Visível só enquanto a troca de documento ou de valor está em voo: é nela que a análise roda. --}}
<div
    wire:loading.flex
    wire:target="{{ $targets }}"
    role="status"
    class="mt-2 items-center gap-2 text-sm font-medium text-primary-600 dark:text-primary-400"
>
    <x-filament::loading-indicator class="h-4 w-4 shrink-0" />
    <span>Analisando documento com IA…</span>
</div>
