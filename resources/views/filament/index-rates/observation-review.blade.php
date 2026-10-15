@php
    use App\Support\PuCalculator\PuDisplay;

    /** @var array{quarantined: \Illuminate\Support\Collection, quarantined_total: int, incidents: \Illuminate\Support\Collection, syncs: \Illuminate\Support\Collection, imports: list<array<string, mixed>>, corrections: \Illuminate\Support\Collection} $review */
@endphp

<div class="pu-detail flex flex-col gap-4 text-sm">
    <p class="text-secondary">
        Nada aqui é aprovado sozinho. A observação que a <strong>sincronização</strong> recebeu e não aceitou fica retida e só entra pela decisão governada na tabela "Observações retidas para revisão", nesta página. Valor <strong>retido na importação</strong> (salto de 10× sobre a observação anterior, suspeita de unidade errada) só entra por nova importação com a confirmação marcada. Valor <strong>divergente</strong> do já registrado só muda pela correção governada (ação "Corrigir" na linha da observação), com motivo. A sincronização nunca sobrescreve.
    </p>

    <section class="pu-detail-card" aria-labelledby="pu-review-quarantined">
        <h3 id="pu-review-quarantined" class="pu-detail-card-title">Observações retidas da sincronização ({{ $review['quarantined_total'] }} aguardando revisão)</h3>
        @if ($review['quarantined']->isEmpty())
            <p class="mt-2 text-secondary">Nenhuma observação da sincronização aguarda revisão.</p>
        @else
            <div class="pu-detail-scroll mt-2">
                <table class="pu-detail-table">
                    <thead><tr><th scope="col">Observação</th><th scope="col" class="pu-num">Valor recebido</th><th scope="col" class="pu-num">Referência</th><th scope="col">Motivo</th><th scope="col">Detecção</th></tr></thead>
                    <tbody>
                        @foreach ($review['quarantined'] as $retained)
                            <tr>
                                <th scope="row">{{ $retained->indexer }} {{ PuDisplay::date($retained->rate_date) ?? 'sem data' }}<span class="block text-muted">{{ $retained->source }}</span></th>
                                <td class="pu-num">{{ $retained->received_value !== null ? PuDisplay::decimal((string) $retained->received_value, 8) : ($retained->raw_value ?? '—') }}</td>
                                <td class="pu-num">{{ $retained->reference_value !== null ? PuDisplay::decimal((string) $retained->reference_value, 8) : '—' }}</td>
                                <td><x-filament::badge size="sm" color="warning">{{ $retained->anomaly_type->label() }}</x-filament::badge><span class="block text-secondary">{{ $retained->reason }}</span></td>
                                <td>{{ PuDisplay::dateTime($retained->first_detected_at) }}<span class="block text-muted">{{ $retained->occurrences }} vez(es)</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-2 text-muted">Retida não é índice realizado: não estende curva nem atende a divulgação esperada. Decida (aprovar ou recusar, com motivo) na tabela "Observações retidas para revisão" desta página.</p>
        @endif
    </section>

    <section class="pu-detail-card" aria-labelledby="pu-review-incidents">
        <h3 id="pu-review-incidents" class="pu-detail-card-title">Incidentes de índice abertos</h3>
        @if ($review['incidents']->isEmpty())
            <p class="mt-2 text-secondary">Nenhum incidente de índice aberto.</p>
        @else
            <ul class="pu-ops-list">
                @foreach ($review['incidents'] as $incident)
                    <li><x-filament::badge size="sm" :color="PuDisplay::severityColor($incident->severity)">{{ $incident->severity->label() }}</x-filament::badge> {{ $incident->type->label() }} — {{ $incident->reason }} <span class="text-muted">({{ PuDisplay::dateTime($incident->last_detected_at) }})</span></li>
                @endforeach
            </ul>
        @endif
    </section>

    <section class="pu-detail-card" aria-labelledby="pu-review-imports">
        <h3 id="pu-review-imports" class="pu-detail-card-title">Importações com valor retido, em conflito ou recusado</h3>
        @if ($review['imports'] === [])
            <p class="mt-2 text-secondary">Nenhuma importação recente deixou valor para revisar.</p>
        @else
            @foreach ($review['imports'] as $import)
                <div class="mt-3">
                    <p class="font-semibold">{{ $import['when'] }} · {{ $import['indexer'] }} · {{ $import['file'] }} · {{ $import['who'] }}{{ $import['confirmed_outliers'] ? ' · com confirmação de valores fora do padrão' : '' }}</p>
                    <div class="pu-detail-scroll mt-1">
                        <table class="pu-detail-table">
                            <thead><tr><th scope="col">Situação</th><th scope="col">Data</th><th scope="col" class="pu-num">Valor recebido</th><th scope="col" class="pu-num">Registrado</th><th scope="col">Motivo</th></tr></thead>
                            <tbody>
                                @foreach ($import['rows'] as $row)
                                    <tr>
                                        <th scope="row">{{ $row['situation'] }}</th>
                                        <td>{{ $row['date'] }}</td>
                                        <td class="pu-num">{{ $row['value'] ?? '—' }}</td>
                                        <td class="pu-num">{{ $row['existing'] ?? '—' }}</td>
                                        <td class="text-secondary">{{ $row['reason'] ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if ($import['more'] > 0)
                        <p class="text-muted">… e mais {{ $import['more'] }} linha(s) na trilha de auditoria.</p>
                    @endif
                </div>
            @endforeach
        @endif
    </section>

    <section class="pu-detail-card" aria-labelledby="pu-review-syncs">
        <h3 id="pu-review-syncs" class="pu-detail-card-title">Sincronizações com valor divergente do registrado</h3>
        @if ($review['syncs']->isEmpty())
            <p class="mt-2 text-secondary">Nenhuma sincronização recente encontrou valor divergente.</p>
        @else
            <ul class="pu-ops-list">
                @foreach ($review['syncs'] as $attempt)
                    <li>{{ PuDisplay::dateTime($attempt->started_at) }} · {{ $attempt->indexer }} · {{ $attempt->outcome?->label() ?? 'Em andamento' }} · {{ $attempt->conflicts }} conflito(s)</li>
                @endforeach
            </ul>
            <p class="mt-2 text-muted">O detalhe de cada data divergente fica no registro da sincronização; compare com a fonte e use "Corrigir" na observação, se a fonte estiver certa.</p>
        @endif
    </section>

    <section class="pu-detail-card" aria-labelledby="pu-review-corrections">
        <h3 id="pu-review-corrections" class="pu-detail-card-title">Correções governadas recentes</h3>
        @if ($review['corrections']->isEmpty())
            <p class="mt-2 text-secondary">Nenhuma correção registrada.</p>
        @else
            <div class="pu-detail-scroll mt-2">
                <table class="pu-detail-table">
                    <thead><tr><th scope="col">Observação</th><th scope="col" class="pu-num">Antes</th><th scope="col" class="pu-num">Depois</th><th scope="col">Motivo</th><th scope="col">Quem / quando</th><th scope="col" class="pu-num">Curvas afetadas</th></tr></thead>
                    <tbody>
                        @foreach ($review['corrections'] as $correction)
                            <tr>
                                <th scope="row">{{ $correction->indexer }} {{ PuDisplay::date($correction->rate_date) }}<span class="block text-muted">{{ $correction->origin === 'provider_revision' ? 'Revisão da fonte' : 'Correção manual' }}</span></th>
                                <td class="pu-num">{{ PuDisplay::decimal((string) $correction->previous_rate_value, 8) }}</td>
                                <td class="pu-num">{{ PuDisplay::decimal((string) $correction->new_rate_value, 8) }}</td>
                                <td class="text-secondary">{{ $correction->reason }}</td>
                                <td>{{ $correction->correctedBy?->name ?? '—' }}<span class="block text-muted">{{ PuDisplay::dateTime($correction->corrected_at) }}</span></td>
                                <td class="pu-num">{{ count($correction->affected_curve_versions ?? []) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
