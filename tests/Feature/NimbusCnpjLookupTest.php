<?php

use App\Models\Nimbus\PortalUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function makeNimbusPortalUser(): PortalUser
{
    return PortalUser::query()->create([
        'full_name' => 'Cliente Nimbus',
        'email' => 'cliente.nimbus@example.com',
        'document_number' => '12345678901',
        'phone_number' => '11999999999',
        'status' => 'ACTIVE',
    ]);
}

it('returns company data as json for authenticated nimbus users', function () {
    Http::fake([
        'https://publica.cnpj.ws/cnpj/*' => Http::response([
            'razao_social' => 'Empresa Teste S/A',
            'estabelecimento' => [
                'atividade_principal' => [
                    'descricao' => 'Sociedade de credito',
                ],
                'ddd1' => '11',
                'telefone1' => '40001234',
                'site' => 'empresa-teste.example.com.br',
            ],
        ]),
    ]);

    $this->actingAs(makeNimbusPortalUser(), 'nimbus')
        ->postJson(route('nimbus.submissions.cnpj-lookup'), [
            'cnpj' => '11.257.352/0001-43',
        ])
        ->assertOk()
        ->assertJson([
            'data' => [
                'name' => 'Empresa Teste S/A',
                'main_activity' => 'Sociedade de credito',
                'phone' => '(11) 4000-1234',
                'website' => 'https://empresa-teste.example.com.br',
            ],
        ]);
});

it('validates the cnpj before calling the upstream service', function () {
    Http::fake();

    $this->actingAs(makeNimbusPortalUser(), 'nimbus')
        ->postJson(route('nimbus.submissions.cnpj-lookup'), [
            'cnpj' => '123',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['cnpj']);

    Http::assertNothingSent();
});

it('returns a controlled json error when the upstream lookup fails', function () {
    Http::fake([
        'https://publica.cnpj.ws/cnpj/*' => Http::response([], 404),
    ]);

    $this->actingAs(makeNimbusPortalUser(), 'nimbus')
        ->postJson(route('nimbus.submissions.cnpj-lookup'), [
            'cnpj' => '11257352000143',
        ])
        ->assertUnprocessable()
        ->assertJson([
            'error' => 'Nao foi possivel localizar dados para este CNPJ.',
        ]);
});
