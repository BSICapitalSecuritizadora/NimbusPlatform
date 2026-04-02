<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-semibold text-zinc-900">Minhas Emissoes</h1>
        <p class="mt-1 text-sm text-zinc-500">Acompanhe as emissoes vinculadas ao seu cadastro no portal.</p>
    </div>

    <flux:card class="space-y-4">
        @if ($emissions->isEmpty())
            <div class="py-8 text-center text-zinc-500">
                <flux:icon.building-office-2 class="mx-auto mb-3 h-12 w-12 text-zinc-300" />
                <h2 class="text-lg font-medium text-zinc-900">Nenhuma emissao vinculada.</h2>
                <p>Quando houver emissoes associadas ao seu cadastro, elas aparecerao aqui.</p>
            </div>
        @else
            <div class="space-y-3">
                @foreach ($emissions as $emission)
                    <flux:card wire:key="emission-{{ $emission->id }}" class="space-y-2 p-4">
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h2 class="text-base font-medium text-zinc-900">{{ $emission->name }}</h2>
                                <p class="text-sm text-zinc-500">Tipo: {{ $emission->type }}</p>
                            </div>

                            <flux:badge color="zinc" size="sm">
                                {{ $emission->status_label }}
                            </flux:badge>
                        </div>
                    </flux:card>
                @endforeach
            </div>
        @endif
    </flux:card>
</div>
