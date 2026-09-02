<?php

use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\Contract;
use App\Models\Emission;
use App\Models\Negotiation;
use Carbon\Carbon;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('detail drill-down view shows tipo code empreendimento bloco unidade and formatted date', function () {
    $emission = Emission::factory()->withContractNegotiations()->create(['name' => 'CRI Alto Bellevue Details']);
    $construction = Construction::factory()->for($emission)->create(['development_name' => 'Alto Bellevue']);
    $unit = ConstructionUnit::factory()->forConstruction($construction)->create(['block' => 'C', 'unit' => '7']);

    $record = new Negotiation([
        'emission_id' => $emission->id,
        'construction_id' => $construction->id,
        'reference_month' => '2026-07-01',
        'sales' => 3,
        'cancellations' => 2,
    ]);
    $record->setRelation('emission', $emission);
    $record->setRelation('construction', $construction);

    // Simulate detailEvents output as used by the table modal
    $events = [
        [
            'type' => 'Venda',
            'code' => '10070766',
            'development' => 'Alto Bellevue',
            'block' => 'C',
            'unit' => '7',
            'date' => Carbon::parse('2026-07-06'),
            'date_formatted' => '06/07/2026',
            'display' => 'Bloco C — Unidade 7',
            'contract_id' => 1,
        ],
        [
            'type' => 'Distrato',
            'code' => '99999999',
            'development' => 'Alto Bellevue',
            'block' => 'A',
            'unit' => '99',
            'date' => Carbon::parse('2026-07-15'),
            'date_formatted' => '15/07/2026',
            'display' => 'Bloco A — Unidade 99',
            'contract_id' => 2,
        ],
    ];

    $html = view('filament.negotiations.contract-details', [
        'record' => $record,
        'events' => $events,
    ])->render();

    // Required columns/fields per spec
    expect($html)->toContain('Tipo')
        ->toContain('Código do contrato')
        ->toContain('Empreendimento')
        ->toContain('Bloco')
        ->toContain('Unidade')
        ->toContain('Data')
        // Data uses dd/mm/yyyy
        ->toContain('06/07/2026')
        ->toContain('15/07/2026')
        // Contract codes readable
        ->toContain('10070766')
        ->toContain('99999999')
        // Development name
        ->toContain('Alto Bellevue')
        // Distinguish Venda/Distrato badges
        ->toContain('Venda')
        ->toContain('Distrato');
});

it('detail view handles long names without breaking layout and shows empty state', function () {
    $emission = Emission::factory()->withContractNegotiations()->create(['name' => str_repeat('Emissão Longa ', 10)]);
    $construction = Construction::factory()->for($emission)->create(['development_name' => str_repeat('Empreendimento Muito Longo ', 10)]);

    $record = new Negotiation([
        'emission_id' => $emission->id,
        'construction_id' => $construction->id,
        'reference_month' => '2026-07-01',
        'sales' => 0,
        'cancellations' => 0,
    ]);
    $record->setRelation('emission', $emission);
    $record->setRelation('construction', $construction);

    $htmlEmpty = view('filament.negotiations.contract-details', [
        'record' => $record,
        'events' => [],
    ])->render();

    expect($htmlEmpty)->toContain('Nenhuma movimentação encontrada');

    // Long contract code does not break — uses truncate + tabular-nums
    $events = [
        [
            'type' => 'Venda',
            'code' => str_repeat('X', 40),
            'development' => $construction->development_name,
            'block' => 'BLOCO-MUITO-LONGO',
            'unit' => '9999',
            'date' => Carbon::parse('2026-07-06'),
            'date_formatted' => '06/07/2026',
            'display' => 'Bloco BLOCO-MUITO-LONGO — Unidade 9999',
            'contract_id' => 1,
        ],
    ];

    $htmlLong = view('filament.negotiations.contract-details', [
        'record' => $record,
        'events' => $events,
    ])->render();

    // New layout: desktop fits without horizontal scroll (table-fixed + colgroup)
    expect($htmlLong)->not->toContain('overflow-x-auto')
        ->toContain('table-fixed')
        ->toContain('truncate')
        ->toContain('hidden sm:block')
        ->toContain('sm:hidden');

    // Source block text must be the simplified sentence
    expect($htmlLong)->toContain('Fonte dos dados: Contratos')
        ->toContain('Vendas pela data da venda e distratos pela data de cancelamento.');
});

it('verDetalhes action is read-only with single Fechar footer', function () {
    $source = file_get_contents(app_path('Filament/Resources/Negotiations/Tables/NegotiationsTable.php'));

    expect($source)->toContain('->modalSubmitAction(false)')
        ->toContain("->modalCancelActionLabel('Fechar')")
        ->toContain('Width::FiveExtraLarge')
        ->toContain('->slideOver()');

    // No submit labels like Enviar/Salvar should remain on this read-only action
    $verBlock = substr($source, strpos($source, "Action::make('verDetalhes')"), 1200);
    expect($verBlock)->not->toContain('Enviar')
        ->not->toContain('Salvar')
        ->not->toContain('Confirmar');
});
