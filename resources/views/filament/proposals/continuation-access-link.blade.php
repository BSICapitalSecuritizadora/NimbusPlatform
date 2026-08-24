<div x-data="{ copied: false }" class="space-y-3">
    <div class="rounded-lg bg-gray-50 p-3 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
        <p x-ref="link" class="break-all font-mono text-xs leading-relaxed text-gray-700 dark:text-gray-300">{{ $url }}</p>
    </div>

    <button
        type="button"
        x-on:click="navigator.clipboard.writeText($refs.link.innerText).then(() => { copied = true; setTimeout(() => copied = false, 2000) })"
        class="inline-flex items-center gap-1.5 text-sm font-medium text-primary-600 transition hover:text-primary-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 dark:text-primary-400 dark:hover:text-primary-300"
    >
        <x-filament::icon icon="heroicon-m-clipboard-document" class="h-4 w-4" />
        <span x-text="copied ? 'Link copiado!' : 'Copiar link completo'">Copiar link completo</span>
    </button>
</div>
