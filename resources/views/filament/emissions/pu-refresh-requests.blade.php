@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\PuObligationRefreshRequest> $requests */
@endphp

<div class="pu-detail flex flex-col gap-3 text-sm">
    @if ($requests->isEmpty())
        <p class="text-secondary">Nenhum pedido de atualização aberto para esta emissão. Retomar registra um pedido manual novo e o executa agora.</p>
    @else
        <div class="pu-detail-scroll">
            <table class="pu-detail-table">
                <thead>
                    <tr>
                        <th scope="col">Pedido</th>
                        <th scope="col">Situação</th>
                        <th scope="col" class="pu-num">Tentativas</th>
                        <th scope="col">Última falha</th>
                        <th scope="col">Repetir resolve?</th>
                        <th scope="col">Próxima tentativa</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($requests as $request)
                        <tr>
                            <th scope="row">#{{ $request->id }}<span class="block text-muted">{{ $request->trigger }} · {{ \App\Support\PuCalculator\PuDisplay::dateTime($request->requested_at) ?? '—' }}</span></th>
                            <td><x-filament::badge size="sm" :color="\App\Support\PuCalculator\PuDisplay::refreshStatusColor($request->status)">{{ $request->status->label() }}</x-filament::badge></td>
                            <td class="pu-num">{{ $request->attempts }}</td>
                            <td>{{ $request->last_error_category?->label() ?? '—' }}@if (filled($request->last_error_message))<span class="block text-muted">{{ \Illuminate\Support\Str::limit($request->last_error_message, 240) }}</span>@endif</td>
                            <td>
                                @if ($request->last_error_category === null)
                                    —
                                @elseif ($request->last_error_category->isRetryable())
                                    Sim (falha passageira)
                                @else
                                    <strong>Não</strong> — corrigir a causa antes
                                @endif
                            </td>
                            <td>{{ \App\Support\PuCalculator\PuDisplay::dateTime($request->next_attempt_at) ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
