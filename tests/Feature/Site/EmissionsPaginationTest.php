<?php

use App\Models\Emission;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows only the custom operations summary on the emissions paginator', function () {
    Emission::factory()
        ->count(13)
        ->create([
            'is_public' => true,
        ]);

    $this->get(route('site.emissions'))
        ->assertSuccessful()
        ->assertSeeText('Exibindo 1 a 12 de 13 opera')
        ->assertSee('site-pagination-mobile-list', false)
        ->assertSee('site-pagination-mobile-link', false)
        ->assertDontSeeText('resultados');
});

it('shows the sum of only public emissions in distribution', function () {
    Emission::factory()->active()->create([
        'issued_volume' => 1_250_000_000,
        'is_public' => true,
    ]);

    Emission::factory()->active()->create([
        'issued_volume' => 500_000_000,
        'is_public' => true,
    ]);

    foreach (['draft', 'default', 'closed', 'issued', 'cancelled', 'structuring'] as $status) {
        Emission::factory()->create([
            'status' => $status,
            'issued_volume' => 2_000_000_000,
            'is_public' => true,
        ]);
    }

    Emission::factory()->active()->create([
        'issued_volume' => 3_000_000_000,
        'is_public' => false,
    ]);

    $this->get(route('site.emissions'))
        ->assertSuccessful()
        ->assertViewHas(
            'metrics',
            fn (array $metrics): bool => (float) $metrics['distribution_volume'] === 1_750_000_000.0,
        )
        ->assertSeeText('Volume em Distribuição')
        ->assertSeeText('R$ 1,8 bi')
        ->assertDontSeeText('Volume Total Emitido');
});

it('abbreviates distribution volume in millions', function () {
    Emission::factory()->active()->create([
        'issued_volume' => 375_000_000,
        'is_public' => true,
    ]);

    $this->get(route('site.emissions'))
        ->assertSuccessful()
        ->assertSeeText('R$ 375,0 mi');
});

it('shows a zero monetary value when there are no emissions in distribution', function () {
    Emission::factory()->closed()->create([
        'issued_volume' => 900_000_000,
        'is_public' => true,
    ]);

    $this->get(route('site.emissions'))
        ->assertSuccessful()
        ->assertViewHas(
            'metrics',
            fn (array $metrics): bool => (float) $metrics['distribution_volume'] === 0.0,
        )
        ->assertSeeText('R$ 0,00');
});
