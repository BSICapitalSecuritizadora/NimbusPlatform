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
