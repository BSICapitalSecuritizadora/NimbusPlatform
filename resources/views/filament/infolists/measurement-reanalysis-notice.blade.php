@php
    use App\DTOs\Measurements\MeasurementPhysicalProgress;
    use App\Support\BusinessTime;

    /*
     * Reanálise da Engenharia de uma medição vigente: a contribuição física
     * aprovada antes, invalidada quando a medição voltou à Engenharia, fica
     * fora do avanço reconhecido até a nova decisão. Recebe a lista de
     * MeasurementPhysicalProgressService::reanalysisNoticeFor() -- pelo
     * @include da visão geral das revisões ou como estado da entrada do resumo
     * da medição original -- e não mostra nada quando ela está vazia.
     *
     * A soma com a contribuição em reanálise não é o avanço de nenhum momento
     * e não promete que os pontos voltam: a nova aprovação pode ser outra, e a
     * folga pode ter sido usada por outra medição.
     */
    $notice ??= (isset($getState) ? $getState() : null) ?? [];
    $percent = fn (int $basisPoints): string => MeasurementPhysicalProgress::format($basisPoints);
    $points = fn (int $basisPoints): string => str_replace('%', '', MeasurementPhysicalProgress::format($basisPoints)).' p.p.';
    $sentence = function (array $item) use ($percent, $points): string {
        $invalidatedAt = ($item['invalidated_at'] ?? null) instanceof \DateTimeInterface
            ? BusinessTime::at($item['invalidated_at'])->format('d/m/Y')
            : null;

        return 'Avanço físico reconhecido: '.$percent((int) $item['recognized_basis_points']).'. '
            .'Reanálise da Engenharia em andamento: a contribuição de '.$points((int) $item['excluded_basis_points']).' aprovada antes'
            .($invalidatedAt === null ? '' : ' (registro invalidado em '.$invalidatedAt.')')
            .' está fora do avanço reconhecido enquanto a Engenharia reanalisa a medição. '
            .'Somando a contribuição em reanálise: '.$percent((int) $item['with_reanalysis_basis_points'])
            .(($item['exceeds_limit'] ?? false) ? ' — a reaprovação no valor anterior não cabe mais no limite de 100%' : '')
            .'. Isso não indica, por si só, regressão física da obra.';
    };
@endphp

@if (is_array($notice) && $notice !== [])
    <div class="bsi-reanalysis-notice space-y-2">
        @foreach ($notice as $item)
            <div wire:key="reanalysis-notice-{{ $item['plan_set_id'] }}" role="status" class="flex items-start gap-2 rounded-lg border border-sky-200 bg-sky-50 p-3 text-xs text-sky-800 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-300">
                <x-heroicon-o-arrow-path class="mt-0.5 size-4 shrink-0" />
                <div class="min-w-0 space-y-0.5">
                    <p class="font-semibold">{{ $item['label'] }}</p>
                    <p class="leading-relaxed">{{ $sentence($item) }}</p>
                </div>
            </div>
        @endforeach
    </div>
@endif
