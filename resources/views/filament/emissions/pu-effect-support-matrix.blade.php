@php
    /** @var list<array{effect: string, label: string, event_type: ?string, level: string, obligation_type: ?string, components: list<string>, rule: string}> $rows */
    $levelColor = fn (string $level): string => match ($level) {
        'fully_calculated' => 'success',
        'informed_only' => 'info',
        'no_financial_obligation' => 'gray',
        default => 'danger',
    };
@endphp

<div class="pu-detail pu-detail-scroll text-sm">
    <table class="pu-detail-table">
        <thead>
            <tr>
                <th scope="col">Efeito contratual</th>
                <th scope="col">Situação no sistema</th>
                <th scope="col">Formulário</th>
                <th scope="col">Regra</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                @php $type = \App\Domain\PuCalculator\Enums\PuEventType::tryFrom((string) $row['event_type']); @endphp
                <tr>
                    <th scope="row">{{ $row['label'] }}</th>
                    <td><x-filament::badge size="sm" :color="$levelColor($row['level'])">{{ \App\Domain\PuCalculator\Enums\PuFinancialEffectSupportLevel::from($row['level'])->label() }}</x-filament::badge></td>
                    <td>{{ $type !== null && in_array($type, \App\Filament\Resources\Emissions\Schemas\PuContractualEventFields::CREATABLE, true) ? 'Disponível' : 'Não oferecido' }}</td>
                    <td class="text-secondary">{{ $row['rule'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
