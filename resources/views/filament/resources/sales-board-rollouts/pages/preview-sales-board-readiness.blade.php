<x-filament-panels::page>
    @php
        /**
         * Apresentação apenas: o resultado vem pronto da ação "Calcular prévia",
         * só com agregados. Nada aqui consulta a fonte.
         *
         * @var array<string, mixed>|null $preview
         */
        $preview = $this->preview;
        $buckets = [
            'stock' => 'Estoque',
            'financed' => 'Financiado',
            'settled' => 'Quitado',
            'exchanged' => 'Permutado',
            'undetermined' => 'Indeterminado',
        ];
    @endphp

    @if ($preview === null)
        <x-filament::section>
            <div class="bsi-readiness-empty flex flex-col items-start gap-2">
                <p class="text-sm font-semibold">Nenhuma prévia calculada.</p>
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    Escolha a competência e calcule a prévia. Nada é gravado.
                </p>
            </div>
        </x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">Competência {{ $preview['reference_month'] }}</x-slot>
            <x-slot name="description">
                Posição em {{ $preview['position_date'] }} · calculada em {{ $preview['calculated_at'] }}
            </x-slot>

            <dl class="grid gap-x-6 gap-y-4 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Devida em</dt>
                    <dd class="mt-1 text-sm font-semibold tabular-nums">{{ $preview['due_date'] }} (dia 13)</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Coberta pela automação</dt>
                    <dd class="mt-1 text-sm font-semibold">{{ $preview['automation_covers'] ? 'Sim' : 'Não' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Empreendimentos prontos</dt>
                    <dd class="mt-1 text-sm font-semibold tabular-nums">
                        {{ $preview['ready_count'] }} de {{ count($preview['constructions']) }}
                    </dd>
                </div>
            </dl>

            @unless ($preview['competence_closed'])
                <p class="mt-4 rounded-md bg-warning-50 p-3 text-sm text-warning-700 dark:bg-warning-400/10 dark:text-warning-400" role="note">
                    A competência {{ $preview['reference_month'] }} ainda não terminou no calendário de negócio: a posição é parcial
                    e vai mudar até o último dia do mês.
                </p>
            @endunless

            @if ($preview['emission_liquidated'])
                <p class="mt-4 rounded-md bg-gray-100 p-3 text-sm text-gray-700 dark:bg-white/5 dark:text-gray-300" role="note">
                    A Emissão está liquidada: a automação não gera competências para ela. Congelar a competência continua
                    disponível em “Ciclos do Quadro”.
                </p>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Empreendimentos</x-slot>
            <x-slot name="description">
                O que a apuração encontraria hoje em cada empreendimento. Bloqueio impede o congelamento; aviso não impede.
            </x-slot>

            @if ($preview['constructions'] === [])
                <p class="text-sm text-gray-600 dark:text-gray-300">A Emissão não tem empreendimentos.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="bsi-readiness-table w-full text-sm">
                        <thead class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">
                            <tr>
                                <th class="py-2 pr-4">Empreendimento</th>
                                <th class="py-2 pr-4">Situação</th>
                                <th class="py-2 pr-4">Ciclo existente</th>
                                @foreach ($buckets as $label)
                                    <th class="py-2 pr-4 text-right">{{ $label }}</th>
                                @endforeach
                                <th class="py-2 pr-4">Bloqueios</th>
                                <th class="py-2 pr-4">Avisos</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($preview['constructions'] as $row)
                                <tr class="border-b border-gray-100 align-top dark:border-gray-800" wire:key="readiness-construction-{{ $row['id'] }}">
                                    <td class="py-2 pr-4 font-medium">{{ $row['name'] }}</td>
                                    {{--
                                        A situação é a informação principal da linha. O selo trunca o
                                        rótulo para caber na coluna, e a tabela dava a largura às colunas
                                        de bloqueios e avisos: "Bloqueada" saía "Bloqu…". Com a largura
                                        mínima do próprio rótulo, a coluna não encolhe abaixo dele -- a
                                        tabela já rola dentro do contêiner.
                                    --}}
                                    <td class="py-2 pr-4">
                                        <x-filament::badge class="min-w-max" :color="$row['ready'] ? 'success' : 'danger'">
                                            {{ $row['ready'] ? 'Pronta' : 'Bloqueada' }}
                                        </x-filament::badge>
                                    </td>
                                    <td class="py-2 pr-4">
                                        @if ($row['cycle'] === null)
                                            <span class="text-gray-500 dark:text-gray-400">Nenhum</span>
                                        @else
                                            <a
                                                href="{{ \App\Filament\Resources\SalesBoardCycles\SalesBoardCycleResource::getUrl('view', ['record' => $row['cycle']['id']]) }}"
                                                class="text-primary-600 underline-offset-2 hover:underline dark:text-primary-400"
                                            >{{ $row['cycle']['status'] }}</a>
                                        @endif
                                    </td>
                                    @foreach (array_keys($buckets) as $bucket)
                                        <td class="py-2 pr-4 text-right tabular-nums">{{ $row['buckets'][$bucket] }}</td>
                                    @endforeach
                                    <td class="py-2 pr-4">
                                        @if ($row['blockers'] === [])
                                            <span class="text-gray-500 dark:text-gray-400">—</span>
                                        @else
                                            <ul class="space-y-2">
                                                @foreach ($row['blockers'] as $issue)
                                                    <li>
                                                        {{ $issue['label'] }}
                                                        <span class="font-mono text-xs">({{ $issue['code'] }}{{ $issue['count'] === null ? '' : ' · '.$issue['count'] }})</span>
                                                        @if ($issue['hint'])
                                                            <span class="block text-xs text-gray-600 dark:text-gray-300">{{ $issue['hint'] }}</span>
                                                        @endif
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @endif
                                    </td>
                                    <td class="py-2 pr-4">
                                        @if ($row['warnings'] === [])
                                            <span class="text-gray-500 dark:text-gray-400">—</span>
                                        @else
                                            <ul class="space-y-2">
                                                @foreach ($row['warnings'] as $issue)
                                                    <li>
                                                        {{ $issue['label'] }}
                                                        <span class="font-mono text-xs">({{ $issue['code'] }}{{ $issue['count'] === null ? '' : ' · '.$issue['count'] }})</span>
                                                        @if ($issue['hint'])
                                                            <span class="block text-xs text-gray-600 dark:text-gray-300">{{ $issue['hint'] }}</span>
                                                        @endif
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Integridade dos quadros registrados</x-slot>
            <x-slot name="description">
                O leitor de posição resolve cada empreendimento sem olhar a Emissão gravada no quadro: um quadro fora da
                Emissão do empreendimento, ou dois quadros no mesmo mês, mudam o que o relatório e as garantias enxergam.
            </x-slot>

            @if ($preview['misplaced_boards'] === [] && $preview['duplicated_competences'] === [])
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    Nenhum quadro fora da Emissão do empreendimento e nenhuma competência com mais de um quadro.
                </p>
            @else
                @if ($preview['misplaced_boards'] !== [])
                    <p class="text-sm font-semibold">Quadros gravados sob uma Emissão diferente da atual do empreendimento</p>
                    <ul class="mt-2 space-y-1 text-sm">
                        @foreach ($preview['misplaced_boards'] as $board)
                            <li>
                                Quadro #{{ $board['board_id'] }} · {{ $board['construction'] }} · {{ $board['reference_month'] }}
                                <span class="text-gray-600 dark:text-gray-300">
                                    — gravado na Emissão #{{ $board['board_emission_id'] }}; o empreendimento está na Emissão #{{ $board['construction_emission_id'] ?? '—' }}.
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($preview['duplicated_competences'] !== [])
                    <p class="mt-4 text-sm font-semibold">Mais de um quadro para o mesmo empreendimento na mesma competência</p>
                    <ul class="mt-2 space-y-1 text-sm">
                        @foreach ($preview['duplicated_competences'] as $competence)
                            <li>
                                {{ $competence['construction'] }} · {{ $competence['reference_month'] }}
                                <span class="text-gray-600 dark:text-gray-300">
                                    — quadros {{ $competence['board_ids'] }}, nas Emissões {{ $competence['board_emission_ids'] }}.
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
