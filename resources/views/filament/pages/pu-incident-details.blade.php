@php
    use App\Support\PuCalculator\PuDisplay;

    /** @var \App\Models\PuOperationalIncident $incident */
    /** @var string $guidance */
    /** @var array{label: string, url: string}|null $link */
    /** @var string $target */
    $contextLabels = [
        'operational_emissions' => 'Emissões em operação por indexador',
        'attempt_id' => 'Tentativa de sincronização',
        'sync_attempt_id' => 'Tentativa de sincronização',
        'sync_outcome' => 'Resultado da sincronização',
        'conflicts' => 'Conflitos de valor',
        'expected_available_at' => 'Divulgação esperada',
        'indexer' => 'Indexador',
        'count' => 'Quantidade',
        'obligations' => 'Obrigações',
        'requests' => 'Pedidos',
        'freshness' => 'Atualidade',
        'realized_through' => 'Realizada até',
        'expected_realized_through' => 'Esperado realizar até',
        'reprocessing_from' => 'Reprocessar a partir de',
        'contractual_change_from' => 'Mudança contratual a partir de',
        'failure_category' => 'Categoria da falha',
        'difference' => 'Diferença',
    ];
    $context = is_array($incident->context) ? $incident->context : [];
@endphp

<div class="pu-detail flex flex-col gap-4 text-sm">
    <div class="flex flex-wrap items-center gap-2">
        <x-filament::badge :color="PuDisplay::severityColor($incident->severity)" :icon="PuDisplay::severityIcon($incident->severity)">{{ $incident->severity->label() }}</x-filament::badge>
        <x-filament::badge :color="PuDisplay::incidentStatusColor($incident->status)" :icon="PuDisplay::incidentStatusIcon($incident->status)">{{ $incident->status->label() }}</x-filament::badge>
    </div>

    <section class="pu-detail-card" aria-labelledby="pu-incident-reason">
        <h3 id="pu-incident-reason" class="pu-detail-card-title">Motivo registrado pelo monitor</h3>
        <p class="mt-2 text-secondary">{{ $incident->reason }}</p>
    </section>

    <section class="pu-ops-next" aria-labelledby="pu-incident-action">
        <h3 id="pu-incident-action" class="pu-ops-next-title">Ação recomendada</h3>
        <p class="mt-1 text-secondary">{{ $guidance }}</p>
        @if ($link !== null)
            <p class="mt-3">
                <x-filament::link :href="$link['url']" icon="heroicon-m-arrow-top-right-on-square">{{ $link['label'] }}</x-filament::link>
            </p>
        @endif
    </section>

    <dl class="pu-detail-grid">
        <div><dt>Emissão</dt><dd>{{ $incident->emission?->name ?? 'Sistema / índice' }}</dd></div>
        <div><dt>Alvo</dt><dd>{{ $target }}</dd></div>
        <div><dt>Primeira detecção</dt><dd>{{ PuDisplay::dateTime($incident->first_detected_at) ?? '—' }}</dd></div>
        <div><dt>Última detecção</dt><dd>{{ PuDisplay::dateTime($incident->last_detected_at) ?? '—' }}</dd></div>
        <div><dt>Detecções</dt><dd>{{ $incident->detection_count }}</dd></div>
        <div><dt>Recorrência</dt><dd>{{ $incident->recurrence_of_id !== null ? 'Sim, de #'.$incident->recurrence_of_id : 'Não' }}</dd></div>
        <div><dt>Reconhecimento</dt><dd>{{ $incident->acknowledged_at !== null ? sprintf('%s em %s', $incident->acknowledgedBy?->name ?? '—', PuDisplay::dateTime($incident->acknowledged_at)) : 'Não reconhecido' }}</dd></div>
        <div><dt>Resolução</dt><dd>{{ $incident->resolved_at !== null ? 'Pelo monitor em '.PuDisplay::dateTime($incident->resolved_at) : 'Em aberto' }}</dd></div>
        <div><dt>Aviso</dt><dd>{{ $incident->notified_at !== null ? 'Enviado em '.PuDisplay::dateTime($incident->notified_at) : ($incident->notification_error !== null ? 'Falhou: '.$incident->notification_error : 'Não enviado') }}</dd></div>
        <div><dt>Identificador</dt><dd class="font-mono text-xs">#{{ $incident->id }} · {{ $incident->incident_key }}</dd></div>
    </dl>

    @if (filled($incident->acknowledgement_note))
        <p class="text-secondary"><strong>Observação do reconhecimento:</strong> {{ $incident->acknowledgement_note }}</p>
    @endif

    @if ($context !== [])
        <section class="pu-detail-card" aria-labelledby="pu-incident-context">
            <h3 id="pu-incident-context" class="pu-detail-card-title">Contexto</h3>
            <table class="pu-detail-table mt-2">
                <tbody>
                    @foreach ($context as $key => $value)
                        <tr>
                            <th scope="row">{{ $contextLabels[$key] ?? \Illuminate\Support\Str::headline((string) $key) }}</th>
                            <td class="break-all">{{ is_scalar($value) || $value === null ? ($value ?? '—') : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif

    <p class="text-muted">Reconhecer não altera o PU oficial, liquidações, eventos contratuais, curvas nem conflitos de liquidação.</p>
</div>
