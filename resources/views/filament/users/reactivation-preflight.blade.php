{{-- O que a reativação devolve, listado antes da confirmação. --}}
<div class="space-y-4 text-sm">
    @if ($directResponsibilities !== [])
        <div>
            <p class="font-semibold text-gray-950 dark:text-white">
                Responsabilidades diretas que voltam a produzir autoridade
            </p>
            <ul class="mt-1 list-disc space-y-0.5 ps-5 text-gray-600 dark:text-gray-300">
                @foreach ($directResponsibilities as $line)
                    <li>{{ $line }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($delegations !== [])
        <div>
            <p class="font-semibold text-gray-950 dark:text-white">
                Delegações que voltam a ser efetivas
            </p>
            <ul class="mt-1 list-disc space-y-0.5 ps-5 text-gray-600 dark:text-gray-300">
                @foreach ($delegations as $line)
                    <li>{{ $line }}</li>
                @endforeach
            </ul>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                Para impedir que alguma delas volte, revogue-a antes de reativar.
            </p>
        </div>
    @endif
</div>
