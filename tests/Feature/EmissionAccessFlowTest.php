<?php

use App\Mail\EmissionAccessCodeMail;
use App\Models\Emission;
use App\Models\EmissionAccess;
use App\Models\Investor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

it('shows the access request form to public visitors before rendering emission details', function () {
    $emission = Emission::factory()->active()->create([
        'name' => 'CRI Dom Aloysio',
        'type' => 'CRI',
        'if_code' => '26C0589381',
        'is_public' => true,
    ]);

    $this->get(route('site.emissions.show', $emission->if_code))
        ->assertSuccessful()
        ->assertSeeText('Solicitar acesso à operação')
        ->assertSeeText('Nome completo')
        ->assertSee('placeholder="(11) 99999-9999"', false)
        ->assertSee('formatBrazilianPhone', false)
        ->assertDontSeeText('Detalhe da emissão');
});

it('creates an emission access request and sends the code by email', function () {
    Mail::fake();

    $emission = Emission::factory()->active()->create([
        'name' => 'CRI Dom Aloysio',
        'type' => 'CRI',
        'if_code' => '26C0589381',
        'issuer' => 'BSI Capital Securitizadora',
        'is_public' => true,
    ]);

    $response = $this->post(route('site.emissions.access.store', $emission->if_code), [
        'name' => 'Maria da Silva',
        'email' => 'maria@example.test',
        'phone' => '(11) 99999-1234',
    ]);

    $access = EmissionAccess::query()->first();

    $response
        ->assertRedirect(route('site.emissions.access.show', $access))
        ->assertSessionHas('success');

    expect($access)->not->toBeNull()
        ->and($access?->emission_id)->toBe($emission->id)
        ->and($access?->requester_name)->toBe('Maria da Silva')
        ->and($access?->requester_email)->toBe('maria@example.test')
        ->and($access?->requester_phone)->toBe('(11) 99999-1234')
        ->and($access?->sent_at)->not->toBeNull();

    Mail::assertSent(EmissionAccessCodeMail::class, function (EmissionAccessCodeMail $mail) use ($access, $emission): bool {
        return $mail->hasTo('maria@example.test')
            && $mail->access->is($access)
            && $mail->emission->is($emission);
    });
});

it('renders the code validation screen from the emailed access link', function () {
    $emission = Emission::factory()->active()->create([
        'name' => 'CRI Dom Aloysio',
        'if_code' => '26C0589381',
        'is_public' => true,
    ]);

    $access = EmissionAccess::factory()->for($emission)->create([
        'requester_email' => 'maria@example.test',
    ]);

    $this->get(route('site.emissions.access.show', $access))
        ->assertSuccessful()
        ->assertSeeText('Confirme o código de acesso')
        ->assertSeeText('Código de acesso');

    expect($access->fresh()->first_accessed_at)->not->toBeNull();
});

it('validates the code and unlocks the emission detail', function () {
    $emission = Emission::factory()->active()->create([
        'name' => 'CRI Dom Aloysio',
        'if_code' => '26C0589381',
        'is_public' => true,
    ]);

    $access = EmissionAccess::factory()->for($emission)->create();

    $this->post(route('site.emissions.access.verify', $access), [
        'code' => '123456',
    ])
        ->assertRedirect(route('site.emissions.show', $emission->if_code))
        ->assertSessionHas('success')
        ->assertSessionHas(
            EmissionAccess::authorizationSessionKeyForEmission($emission->id),
            $access->id,
        );

    expect($access->fresh()->verified_at)->not->toBeNull();

    $this->withSession([
        EmissionAccess::authorizationSessionKeyForEmission($emission->id) => $access->id,
    ])->get(route('site.emissions.show', $emission->if_code))
        ->assertSuccessful()
        ->assertSeeText('Detalhe da emissão')
        ->assertSeeText('Resumo operacional');
});

it('rejects an invalid emission access code', function () {
    $emission = Emission::factory()->active()->create([
        'name' => 'CRI Dom Aloysio',
        'if_code' => '26C0589381',
        'is_public' => true,
    ]);

    $access = EmissionAccess::factory()->for($emission)->create();

    $this->from(route('site.emissions.access.show', $access))
        ->post(route('site.emissions.access.verify', $access), [
            'code' => '000000',
        ])
        ->assertRedirect(route('site.emissions.access.show', $access))
        ->assertSessionHasErrors('code');

    expect($access->fresh()->verified_at)->toBeNull();
});

it('allows authenticated investors linked to the emission to bypass the public access gate', function () {
    $emission = Emission::factory()->active()->create([
        'name' => 'CRI Dom Aloysio',
        'if_code' => '26C0589381',
        'is_public' => true,
    ]);

    $investor = Investor::factory()->create();

    $emission->investors()->attach($investor->id);

    $this->actingAs($investor, 'investor')
        ->get(route('site.emissions.show', $emission->if_code))
        ->assertSuccessful()
        ->assertSeeText('Detalhe da emissão')
        ->assertDontSeeText('Solicitar acesso à operação');
});
