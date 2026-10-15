@php
    use App\Support\PuCalculator\PuDisplay;

    /** @var \App\Models\IndexRate $rate */
    /** @var list<array{id: int, emission_id: int, emission: string, calculation_version: string, status: string, first_dependent_date: string}> $affected */
@endphp

<div class="pu-detail flex flex-col gap-4 text-sm">
    <dl class="pu-detail-grid">
        <div><dt>Indexador</dt><dd>{{ $rate->indexer }}</dd></div>
        <div><dt>Data da observação</dt><dd>{{ PuDisplay::date($rate->rate_date) }}</dd></div>
        <div><dt>Valor atual</dt><dd class="pu-tabular">{{ PuDisplay::decimal((string) $rate->rate_value, 8) }}{{ $rate->indexer === 'CDI' ? ' % a.a.' : '' }}</dd></div>
        <div><dt>Origem atual</dt><dd>{{ $rate->source ?? '—' }}@if (filled($rate->source_reference))<span class="block text-muted">{{ $rate->source_reference }}</span>@endif</dd></div>
        <div><dt>Registrada em</dt><dd>{{ PuDisplay::dateTime($rate->fetched_at ?? $rate->created_at) ?? '—' }}</dd></div>
    </dl>

    <section class="pu-detail-card" aria-labelledby="pu-correction-affected">
        <h3 id="pu-correction-affected" class="pu-detail-card-title">Curvas que usaram esta observação (prévia)</h3>
        <p class="pu-detail-hint">A lista definitiva é relida no momento da correção, com as emissões travadas.</p>
        @if ($affected === [])
            <p class="mt-2 text-secondary">Nenhuma versão viva de curva usou esta observação: a correção não pede reprocessamento.</p>
        @else
            <div class="pu-detail-scroll mt-2">
                <table class="pu-detail-table">
                    <thead>
                        <tr>
                            <th scope="col">Emissão</th>
                            <th scope="col">Versão</th>
                            <th scope="col">Situação</th>
                            <th scope="col">Afetada a partir de</th>
                            <th scope="col">Consequência</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($affected as $version)
                            @php $status = \App\Domain\PuCalculator\Enums\PuCurveStatus::tryFrom($version['status']); @endphp
                            <tr>
                                <th scope="row">{{ $version['emission'] }}</th>
                                <td>{{ $version['calculation_version'] }}</td>
                                <td>{{ $status?->label() ?? $version['status'] }}</td>
                                <td>{{ \Carbon\CarbonImmutable::parse($version['first_dependent_date'])->format('d/m/Y') }}</td>
                                <td>{{ $status?->isOfficial() ? 'Oficial: fica com reprocessamento necessário; linhas homologadas mantidas.' : 'Não oficial: a próxima geração usa o valor corrigido.' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
