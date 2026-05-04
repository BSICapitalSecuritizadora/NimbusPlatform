<?php

use App\Models\Nimbus\PortalUser;
use App\Models\Nimbus\Submission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('stores a nimbus submission using the current database schema', function () {
    $portalUser = PortalUser::query()->create([
        'full_name' => 'Cliente Nimbus',
        'email' => 'cliente.nimbus@example.com',
        'document_number' => '12345678901',
        'phone_number' => '11999999999',
        'status' => 'ACTIVE',
    ]);

    $shareholders = [
        [
            'name' => 'Socio Principal',
            'rg' => '12.345.678-9',
            'cnpj' => '11.111.111/0001-11',
            'percentage' => 100,
        ],
    ];

    $response = $this->actingAs($portalUser, 'nimbus')
        ->withHeader('User-Agent', 'Pest Nimbus Browser')
        ->post(route('nimbus.submissions.store'), [
            'responsible_name' => 'Kamilly Regina Bernardes',
            'company_cnpj' => '11.257.352/0001-43',
            'company_name' => 'BSI Capital Securitizadora S/A',
            'main_activity' => 'Securitizacao de creditos',
            'phone' => '(11) 4330-9780',
            'website' => 'https://bsicapital.com.br/',
            'net_worth' => 'R$ 10,00',
            'annual_revenue' => 'R$ 100,00',
            'registrant_name' => 'Kamilly Regina Bernardes',
            'registrant_position' => 'Teste',
            'registrant_rg' => '49.424.335-1',
            'registrant_cpf' => '019.348.404-83',
            'is_us_person' => '0',
            'is_pep' => '0',
            'shareholders' => json_encode($shareholders, JSON_THROW_ON_ERROR),
            'ultimo_balanco' => UploadedFile::fake()->create('ultimo-balanco.pdf', 100, 'application/pdf'),
            'dre' => UploadedFile::fake()->create('dre.pdf', 100, 'application/pdf'),
            'politicas' => UploadedFile::fake()->create('politicas.pdf', 100, 'application/pdf'),
            'cartao_cnpj' => UploadedFile::fake()->create('cartao-cnpj.pdf', 100, 'application/pdf'),
            'procuracao' => UploadedFile::fake()->create('procuracao.pdf', 100, 'application/pdf'),
            'ata' => UploadedFile::fake()->create('ata.pdf', 100, 'application/pdf'),
            'contrato_social' => UploadedFile::fake()->create('contrato-social.pdf', 100, 'application/pdf'),
            'estatuto' => UploadedFile::fake()->create('estatuto.pdf', 100, 'application/pdf'),
        ]);

    $submission = Submission::query()
        ->with(['shareholders', 'files.versions'])
        ->firstOrFail();

    $response->assertRedirect(route('nimbus.submissions.show', $submission));

    expect($submission->reference_code)->toStartWith('NMB-')
        ->and($submission->submission_type)->toBe('REGISTRATION')
        ->and($submission->title)->toBe('Solicitação de cadastro - BSI Capital Securitizadora S/A')
        ->and($submission->status)->toBe('PENDING')
        ->and($submission->created_ip)->not->toBeNull()
        ->and($submission->created_user_agent)->toBe('Pest Nimbus Browser')
        ->and($submission->shareholder_data)->toBe($shareholders)
        ->and($submission->shareholders)->toHaveCount(1)
        ->and($submission->shareholders->first()->document_rg)->toBe('12.345.678-9')
        ->and($submission->files)->toHaveCount(8);

    $documentTypes = $submission->files
        ->pluck('document_type')
        ->sort()
        ->values()
        ->all();

    expect($documentTypes)->toBe([
        'ARTICLES_OF_INCORPORATION',
        'BALANCE_SHEET',
        'BYLAWS',
        'CNPJ_CARD',
        'DRE',
        'MINUTES',
        'POLICIES',
        'POWER_OF_ATTORNEY',
    ]);

    foreach ($submission->files as $file) {
        expect($file->origin)->toBe('USER')
            ->and($file->visible_to_user)->toBeFalse()
            ->and($file->versions)->toHaveCount(1)
            ->and($file->storage_path)->toStartWith('nimbus_docs/submissions/'.$submission->id.'/')
            ->and(Storage::disk('local')->exists($file->storage_path))->toBeTrue();
    }

    Storage::disk('local')->deleteDirectory('nimbus_docs/submissions/'.$submission->id);
});

it('rejects submissions when shareholder participation does not total 100 percent', function () {
    $portalUser = PortalUser::query()->create([
        'full_name' => 'Cliente Nimbus',
        'email' => 'cliente.percentual@example.com',
        'document_number' => '12345678902',
        'phone_number' => '11999999999',
        'status' => 'ACTIVE',
    ]);

    $response = $this->actingAs($portalUser, 'nimbus')
        ->post(route('nimbus.submissions.store'), [
            'responsible_name' => 'Cliente Percentual',
            'company_cnpj' => '11.257.352/0001-43',
            'company_name' => 'BSI Capital Securitizadora S/A',
            'main_activity' => 'Securitizacao de creditos',
            'phone' => '(11) 4330-9780',
            'website' => 'https://bsicapital.com.br/',
            'net_worth' => 'R$ 10,00',
            'annual_revenue' => 'R$ 100,00',
            'registrant_name' => 'Cliente Percentual',
            'registrant_position' => 'Teste',
            'registrant_rg' => '49.424.335-1',
            'registrant_cpf' => '019.348.404-83',
            'is_us_person' => '0',
            'is_pep' => '0',
            'shareholders' => json_encode([
                [
                    'name' => 'Socio Parcial',
                    'rg' => '12.345.678-9',
                    'cnpj' => '11.111.111/0001-11',
                    'percentage' => 50,
                ],
            ], JSON_THROW_ON_ERROR),
            'ultimo_balanco' => UploadedFile::fake()->create('ultimo-balanco.pdf', 100, 'application/pdf'),
            'dre' => UploadedFile::fake()->create('dre.pdf', 100, 'application/pdf'),
            'politicas' => UploadedFile::fake()->create('politicas.pdf', 100, 'application/pdf'),
            'cartao_cnpj' => UploadedFile::fake()->create('cartao-cnpj.pdf', 100, 'application/pdf'),
            'procuracao' => UploadedFile::fake()->create('procuracao.pdf', 100, 'application/pdf'),
            'ata' => UploadedFile::fake()->create('ata.pdf', 100, 'application/pdf'),
            'contrato_social' => UploadedFile::fake()->create('contrato-social.pdf', 100, 'application/pdf'),
            'estatuto' => UploadedFile::fake()->create('estatuto.pdf', 100, 'application/pdf'),
        ]);

    $response->assertSessionHasErrors('shareholders');

    $this->assertDatabaseCount('nimbus_submissions', 0);
});
