@php
    $stats = $stats ?? [
        ['label' => 'Emissões Realizadas', 'value' => '+R$ 2Bi'],
        ['label' => 'Projetos Financiados', 'value' => '85+'],
        ['label' => 'Estados Atendidos', 'value' => '15'],
        ['label' => 'VGV Sob Gestão', 'value' => 'R$ 450Mi'],
    ];
@endphp

<section class="py-5" style="background: linear-gradient(135deg, var(--brand-strong), var(--brand));">
    <div class="container py-4">
        <div class="row g-4 text-center">
            @foreach($stats as $stat)
                <div class="col-6 col-md-3">
                    <div class="px-3">
                        <div class="display-5 fw-bold text-white mb-1">{{ $stat['value'] }}</div>
                        <div class="small text-uppercase fw-bold" style="color: var(--gold); letter-spacing: 0.1em;">{{ $stat['label'] }}</div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</section>
