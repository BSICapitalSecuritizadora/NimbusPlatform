<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-zinc-900">Bem-vindo, {{ $investor->name }}</h1>
            <p class="mt-1 text-sm text-zinc-500">Acesse suas emissoes e documentos pelo menu acima.</p>
        </div>
    </div>

    @if ($newDocumentsCount > 0)
        <flux:card class="border-blue-100 bg-blue-50/50">
            <div class="flex items-start gap-4">
                <div class="mt-1">
                    <flux:icon.document-text class="h-6 w-6 text-blue-600" />
                </div>
                <div>
                    <h3 class="text-sm font-medium text-blue-900">Novos documentos disponiveis</h3>
                    <p class="mt-1 text-sm text-blue-700">
                        Voce tem <strong>{{ $newDocumentsCount }}</strong> documento(s) publicado(s) desde o seu ultimo acesso ao portal.
                    </p>
                    <div class="mt-4">
                        <flux:button size="sm" variant="primary" as="a" href="{{ route('investor.documents') }}">
                            Ver documentos
                        </flux:button>
                    </div>
                </div>
            </div>
        </flux:card>
    @endif
</div>
