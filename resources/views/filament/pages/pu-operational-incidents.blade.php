<x-filament-panels::page>
    <div class="pu-ops-next" role="note">
        <p class="pu-ops-next-title">Como ler esta lista</p>
        <ul class="pu-ops-list">
            <li><strong>Ativo</strong>: o monitor detectou a condição e ninguém reconheceu. <strong>Reconhecido</strong>: alguém está tratando. <strong>Resolvido</strong>: o monitor deixou de detectar a condição.</li>
            <li>Reconhecer não resolve: não altera o PU oficial, liquidações, eventos, curvas nem conflitos. Para isso, use a ação indicada em cada incidente.</li>
            <li>A severidade não é só cor: <strong>Crítico</strong> pede intervenção financeira ou de governança; <strong>Atenção</strong> pede que alguém olhe; <strong>Informativo</strong> é esperado.</li>
        </ul>
    </div>

    {{ $this->table }}
</x-filament-panels::page>
