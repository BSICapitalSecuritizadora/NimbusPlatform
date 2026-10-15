@php
    use App\Support\PuCalculator\PuDisplay;

    /** @var \App\Models\IndexRateQuarantine $quarantine */
    /** @var \App\Models\IndexRate|null $current */
    /** @var list<array{id: int, emission_id: int, emission: string, calculation_version: string, status: string, first_dependent_date: string}> $affected */
    $unit = $quarantine->indexer === 'CDI' ? ' % a.a.' : '';
    $hasAccepted = $current !== null && ! $current->isProjectedRate();
@endphp

<div class="pu-detail flex flex-col gap-4 text-sm">
    <dl class="pu-detail-grid">
        <div><dt>Observação</dt><dd>{{ $quarantine->indexer }} · {{ PuDisplay::date($quarantine->rate_date) ?? 'sem data legível' }}</dd></div>
        <div><dt>Valor recebido</dt><dd class="pu-tabular">{{ $quarantine->received_value !== null ? PuDisplay::decimal((string) $quarantine->received_value, 8).$unit : '—' }}</dd></div>
        <div><dt>Como a fonte informou</dt><dd class="pu-tabular">{{ $quarantine->raw_value ?? '—' }}</dd></div>
        <div><dt>Fonte</dt><dd>{{ $quarantine->source }}{{ $quarantine->external_series_code !== null ? ' · série '.$quarantine->external_series_code : '' }}</dd></div>
        <div><dt>Registrado hoje na data</dt><dd class="pu-tabular">{{ $hasAccepted ? PuDisplay::decimal((string) $current->rate_value, 8).$unit.' ('.$current->source.')' : ($current !== null ? 'valor projetado' : 'sem observação aceita') }}</dd></div>
        <div><dt>Detecção</dt><dd>{{ PuDisplay::dateTime($quarantine->first_detected_at) }} · {{ $quarantine->occurrences }} vez(es)</dd></div>
    </dl>

    <section class="pu-detail-card" aria-labelledby="pu-quarantine-reason">
        <h3 id="pu-quarantine-reason" class="pu-detail-card-title">{{ $quarantine->anomaly_type->label() }}</h3>
        <p class="mt-1 text-secondary">{{ $quarantine->reason }}</p>
    </section>

    <section class="pu-detail-card" aria-labelledby="pu-quarantine-path">
        <h3 id="pu-quarantine-path" class="pu-detail-card-title">O que a aprovação faz</h3>
        @if (! $quarantine->anomaly_type->canBeApproved() || $quarantine->received_value === null)
            <p class="mt-1 text-secondary">Não há valor aprovável. Recuse e, conferida a fonte, registre o valor certo pela importação ou pela correção governada.</p>
        @elseif ($current !== null && $current->isProjectedRate())
            <p class="mt-1 text-secondary">A data tem valor projetado: a observação realizada não o substitui por aqui.</p>
        @elseif (! $hasAccepted)
            <p class="mt-1 text-secondary">A data não tem observação aceita: o valor entra pelo registro de observação, com a confirmação explícita do valor fora do padrão. A partir daí ele vale como índice realizado e a extensão diária das curvas pode usá-lo.</p>
        @else
            <p class="mt-1 text-secondary">A data já tem valor aceito: aprovar é correção governada. O valor anterior vai para o livro de correções, e as curvas governadas que usaram a data ficam aguardando reprocessamento — as linhas homologadas não mudam.</p>
            @if ($affected === [])
                <p class="mt-2 text-muted">Nenhuma versão viva de curva usou esta observação.</p>
            @else
                <div class="pu-detail-scroll mt-2">
                    <table class="pu-detail-table">
                        <thead><tr><th scope="col">Emissão</th><th scope="col">Versão</th><th scope="col">Afetada a partir de</th></tr></thead>
                        <tbody>
                            @foreach ($affected as $version)
                                <tr>
                                    <th scope="row">{{ $version['emission'] }}</th>
                                    <td>{{ $version['calculation_version'] }}</td>
                                    <td>{{ \Carbon\CarbonImmutable::parse($version['first_dependent_date'])->format('d/m/Y') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @endif
    </section>
</div>
