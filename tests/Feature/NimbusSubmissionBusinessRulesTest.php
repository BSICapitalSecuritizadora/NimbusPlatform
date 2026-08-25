<?php

use App\Models\Nimbus\PortalUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

function validCnpj(): string
{
    return '11.222.333/0001-81';
} // valid checksum for test (generated)
function invalidCnpj(): string
{
    return '11.222.333/0001-00';
}
function validCpf(): string
{
    return '529.982.247-25';
}
function invalidCpf(): string
{
    return '529.982.247-00';
}

// CNPJ
it('accepts valid formatted CNPJ', function () {
    $user = PortalUser::query()->create(['full_name' => 'CNPJ Test', 'email' => 'cnpj-valid@test.com'.uniqid(), 'document_number' => '12345678901', 'phone_number' => '11999999999', 'status' => 'ACTIVE']);
    $this->actingAs($user, 'nimbus')->post(route('nimbus.submissions.store'), [
        'responsible_name' => 'Resp', 'company_cnpj' => validCnpj(), 'company_name' => 'Empresa', 'phone' => '(11) 99999-9999',
        'net_worth' => '1000', 'annual_revenue' => '1000', 'registrant_name' => 'Reg', 'registrant_cpf' => validCpf(),
        'shareholders' => json_encode([['name' => 'Socio', 'percentage' => 100]]), 'is_anbima_affiliated' => true,
        'ultimo_balanco' => UploadedFile::fake()->create('a.pdf', 100, 'application/pdf'),
        'dre' => UploadedFile::fake()->create('b.pdf', 100, 'application/pdf'),
        'politicas' => UploadedFile::fake()->create('c.pdf', 100, 'application/pdf'),
        'cartao_cnpj' => UploadedFile::fake()->create('d.pdf', 100, 'application/pdf'),
        'contrato_social' => UploadedFile::fake()->create('e.pdf', 100, 'application/pdf'),
        'estatuto' => UploadedFile::fake()->create('f.pdf', 100, 'application/pdf'),
    ])->assertRedirect();
});

it('accepts valid unformatted CNPJ', function () {
    $user = PortalUser::query()->create(['full_name' => 'CNPJ Test', 'email' => 'cnpj-unform@test.com'.uniqid(), 'document_number' => '12345678902', 'phone_number' => '11999999998', 'status' => 'ACTIVE']);
    $digits = preg_replace('/\D+/', '', validCnpj());
    $this->actingAs($user, 'nimbus')->post(route('nimbus.submissions.store'), [
        'responsible_name' => 'Resp', 'company_cnpj' => $digits, 'company_name' => 'Empresa', 'phone' => '(11) 99999-9999',
        'net_worth' => '1000', 'annual_revenue' => '1000', 'registrant_name' => 'Reg', 'registrant_cpf' => validCpf(),
        'shareholders' => json_encode([['name' => 'Socio', 'percentage' => 100]]), 'is_anbima_affiliated' => true,
        'ultimo_balanco' => UploadedFile::fake()->create('a.pdf', 100, 'application/pdf'),
        'dre' => UploadedFile::fake()->create('b.pdf', 100, 'application/pdf'),
        'politicas' => UploadedFile::fake()->create('c.pdf', 100, 'application/pdf'),
        'cartao_cnpj' => UploadedFile::fake()->create('d.pdf', 100, 'application/pdf'),
        'contrato_social' => UploadedFile::fake()->create('e.pdf', 100, 'application/pdf'),
        'estatuto' => UploadedFile::fake()->create('f.pdf', 100, 'application/pdf'),
    ])->assertRedirect();
});

it('rejects invalid checksum CNPJ', function () {
    $user = PortalUser::query()->create(['full_name' => 'CNPJ Test', 'email' => 'cnpj-inv@test.com'.uniqid(), 'document_number' => '12345678903', 'phone_number' => '11999999997', 'status' => 'ACTIVE']);
    $this->actingAs($user, 'nimbus')->post(route('nimbus.submissions.store'), [
        'responsible_name' => 'Resp', 'company_cnpj' => invalidCnpj(), 'company_name' => 'Empresa', 'phone' => '(11) 99999-9999',
        'net_worth' => '1000', 'annual_revenue' => '1000', 'registrant_name' => 'Reg', 'registrant_cpf' => validCpf(),
        'shareholders' => json_encode([['name' => 'Socio', 'percentage' => 100]]), 'is_anbima_affiliated' => true,
        'ultimo_balanco' => UploadedFile::fake()->create('a.pdf', 100, 'application/pdf'),
        'dre' => UploadedFile::fake()->create('b.pdf', 100, 'application/pdf'),
        'politicas' => UploadedFile::fake()->create('c.pdf', 100, 'application/pdf'),
        'cartao_cnpj' => UploadedFile::fake()->create('d.pdf', 100, 'application/pdf'),
        'contrato_social' => UploadedFile::fake()->create('e.pdf', 100, 'application/pdf'),
        'estatuto' => UploadedFile::fake()->create('f.pdf', 100, 'application/pdf'),
    ])->assertSessionHasErrors('company_cnpj');
});

it('rejects invalid length CNPJ', function () {
    $user = PortalUser::query()->create(['full_name' => 'CNPJ Test', 'email' => 'cnpj-len@test.com'.uniqid(), 'document_number' => '12345678904', 'phone_number' => '11999999996', 'status' => 'ACTIVE']);
    $this->actingAs($user, 'nimbus')->post(route('nimbus.submissions.store'), [
        'responsible_name' => 'Resp', 'company_cnpj' => '123', 'company_name' => 'Empresa', 'phone' => '(11) 99999-9999',
        'net_worth' => '1000', 'annual_revenue' => '1000', 'registrant_name' => 'Reg', 'registrant_cpf' => validCpf(),
        'shareholders' => json_encode([['name' => 'Socio', 'percentage' => 100]]), 'is_anbima_affiliated' => true,
        'ultimo_balanco' => UploadedFile::fake()->create('a.pdf', 100, 'application/pdf'),
        'dre' => UploadedFile::fake()->create('b.pdf', 100, 'application/pdf'),
        'politicas' => UploadedFile::fake()->create('c.pdf', 100, 'application/pdf'),
        'cartao_cnpj' => UploadedFile::fake()->create('d.pdf', 100, 'application/pdf'),
        'contrato_social' => UploadedFile::fake()->create('e.pdf', 100, 'application/pdf'),
        'estatuto' => UploadedFile::fake()->create('f.pdf', 100, 'application/pdf'),
    ])->assertSessionHasErrors('company_cnpj');
});

// CPF
it('accepts valid formatted CPF', function () {
    $user = PortalUser::query()->create(['full_name' => 'CPF Test', 'email' => 'cpf-valid@test.com'.uniqid(), 'document_number' => '12345678905', 'phone_number' => '11999999995', 'status' => 'ACTIVE']);
    $this->actingAs($user, 'nimbus')->post(route('nimbus.submissions.store'), [
        'responsible_name' => 'Resp', 'company_cnpj' => validCnpj(), 'company_name' => 'Empresa', 'phone' => '(11) 99999-9999',
        'net_worth' => '1000', 'annual_revenue' => '1000', 'registrant_name' => 'Reg', 'registrant_cpf' => validCpf(),
        'shareholders' => json_encode([['name' => 'Socio', 'percentage' => 100]]), 'is_anbima_affiliated' => true,
        'ultimo_balanco' => UploadedFile::fake()->create('a.pdf', 100, 'application/pdf'),
        'dre' => UploadedFile::fake()->create('b.pdf', 100, 'application/pdf'),
        'politicas' => UploadedFile::fake()->create('c.pdf', 100, 'application/pdf'),
        'cartao_cnpj' => UploadedFile::fake()->create('d.pdf', 100, 'application/pdf'),
        'contrato_social' => UploadedFile::fake()->create('e.pdf', 100, 'application/pdf'),
        'estatuto' => UploadedFile::fake()->create('f.pdf', 100, 'application/pdf'),
    ])->assertRedirect();
});

it('accepts valid unformatted CPF', function () {
    $user = PortalUser::query()->create(['full_name' => 'CPF Test', 'email' => 'cpf-unform@test.com'.uniqid(), 'document_number' => '12345678906', 'phone_number' => '11999999994', 'status' => 'ACTIVE']);
    $digits = preg_replace('/\D+/', '', validCpf());
    $this->actingAs($user, 'nimbus')->post(route('nimbus.submissions.store'), [
        'responsible_name' => 'Resp', 'company_cnpj' => validCnpj(), 'company_name' => 'Empresa', 'phone' => '(11) 99999-9999',
        'net_worth' => '1000', 'annual_revenue' => '1000', 'registrant_name' => 'Reg', 'registrant_cpf' => $digits,
        'shareholders' => json_encode([['name' => 'Socio', 'percentage' => 100]]), 'is_anbima_affiliated' => true,
        'ultimo_balanco' => UploadedFile::fake()->create('a.pdf', 100, 'application/pdf'),
        'dre' => UploadedFile::fake()->create('b.pdf', 100, 'application/pdf'),
        'politicas' => UploadedFile::fake()->create('c.pdf', 100, 'application/pdf'),
        'cartao_cnpj' => UploadedFile::fake()->create('d.pdf', 100, 'application/pdf'),
        'contrato_social' => UploadedFile::fake()->create('e.pdf', 100, 'application/pdf'),
        'estatuto' => UploadedFile::fake()->create('f.pdf', 100, 'application/pdf'),
    ])->assertRedirect();
});

it('rejects invalid checksum CPF', function () {
    $user = PortalUser::query()->create(['full_name' => 'CPF Test', 'email' => 'cpf-inv@test.com'.uniqid(), 'document_number' => '12345678907', 'phone_number' => '11999999993', 'status' => 'ACTIVE']);
    $this->actingAs($user, 'nimbus')->post(route('nimbus.submissions.store'), [
        'responsible_name' => 'Resp', 'company_cnpj' => validCnpj(), 'company_name' => 'Empresa', 'phone' => '(11) 99999-9999',
        'net_worth' => '1000', 'annual_revenue' => '1000', 'registrant_name' => 'Reg', 'registrant_cpf' => invalidCpf(),
        'shareholders' => json_encode([['name' => 'Socio', 'percentage' => 100]]), 'is_anbima_affiliated' => true,
        'ultimo_balanco' => UploadedFile::fake()->create('a.pdf', 100, 'application/pdf'),
        'dre' => UploadedFile::fake()->create('b.pdf', 100, 'application/pdf'),
        'politicas' => UploadedFile::fake()->create('c.pdf', 100, 'application/pdf'),
        'cartao_cnpj' => UploadedFile::fake()->create('d.pdf', 100, 'application/pdf'),
        'contrato_social' => UploadedFile::fake()->create('e.pdf', 100, 'application/pdf'),
        'estatuto' => UploadedFile::fake()->create('f.pdf', 100, 'application/pdf'),
    ])->assertSessionHasErrors('registrant_cpf');
});

it('rejects invalid length CPF', function () {
    $user = PortalUser::query()->create(['full_name' => 'CPF Test', 'email' => 'cpf-len@test.com'.uniqid(), 'document_number' => '12345678908', 'phone_number' => '11999999992', 'status' => 'ACTIVE']);
    $this->actingAs($user, 'nimbus')->post(route('nimbus.submissions.store'), [
        'responsible_name' => 'Resp', 'company_cnpj' => validCnpj(), 'company_name' => 'Empresa', 'phone' => '(11) 99999-9999',
        'net_worth' => '1000', 'annual_revenue' => '1000', 'registrant_name' => 'Reg', 'registrant_cpf' => '123',
        'shareholders' => json_encode([['name' => 'Socio', 'percentage' => 100]]), 'is_anbima_affiliated' => true,
        'ultimo_balanco' => UploadedFile::fake()->create('a.pdf', 100, 'application/pdf'),
        'dre' => UploadedFile::fake()->create('b.pdf', 100, 'application/pdf'),
        'politicas' => UploadedFile::fake()->create('c.pdf', 100, 'application/pdf'),
        'cartao_cnpj' => UploadedFile::fake()->create('d.pdf', 100, 'application/pdf'),
        'contrato_social' => UploadedFile::fake()->create('e.pdf', 100, 'application/pdf'),
        'estatuto' => UploadedFile::fake()->create('f.pdf', 100, 'application/pdf'),
    ])->assertSessionHasErrors('registrant_cpf');
});

// Phone
it('accepts valid formatted phone', function () {
    $user = PortalUser::query()->create(['full_name' => 'Phone', 'email' => 'phone-valid@test.com'.uniqid(), 'document_number' => '12345678909', 'phone_number' => '11999999991', 'status' => 'ACTIVE']);
    $this->actingAs($user, 'nimbus')->post(route('nimbus.submissions.store'), [
        'responsible_name' => 'Resp', 'company_cnpj' => validCnpj(), 'company_name' => 'Empresa', 'phone' => '(11) 99999-9999',
        'net_worth' => '1000', 'annual_revenue' => '1000', 'registrant_name' => 'Reg', 'registrant_cpf' => validCpf(),
        'shareholders' => json_encode([['name' => 'Socio', 'percentage' => 100]]), 'is_anbima_affiliated' => true,
        'ultimo_balanco' => UploadedFile::fake()->create('a.pdf', 100, 'application/pdf'),
        'dre' => UploadedFile::fake()->create('b.pdf', 100, 'application/pdf'),
        'politicas' => UploadedFile::fake()->create('c.pdf', 100, 'application/pdf'),
        'cartao_cnpj' => UploadedFile::fake()->create('d.pdf', 100, 'application/pdf'),
        'contrato_social' => UploadedFile::fake()->create('e.pdf', 100, 'application/pdf'),
        'estatuto' => UploadedFile::fake()->create('f.pdf', 100, 'application/pdf'),
    ])->assertRedirect();
});

it('rejects invalid phone length', function () {
    $user = PortalUser::query()->create(['full_name' => 'Phone', 'email' => 'phone-inv@test.com'.uniqid(), 'document_number' => '12345678910', 'phone_number' => '11999999990', 'status' => 'ACTIVE']);
    $this->actingAs($user, 'nimbus')->post(route('nimbus.submissions.store'), [
        'responsible_name' => 'Resp', 'company_cnpj' => validCnpj(), 'company_name' => 'Empresa', 'phone' => '123',
        'net_worth' => '1000', 'annual_revenue' => '1000', 'registrant_name' => 'Reg', 'registrant_cpf' => validCpf(),
        'shareholders' => json_encode([['name' => 'Socio', 'percentage' => 100]]), 'is_anbima_affiliated' => true,
        'ultimo_balanco' => UploadedFile::fake()->create('a.pdf', 100, 'application/pdf'),
        'dre' => UploadedFile::fake()->create('b.pdf', 100, 'application/pdf'),
        'politicas' => UploadedFile::fake()->create('c.pdf', 100, 'application/pdf'),
        'cartao_cnpj' => UploadedFile::fake()->create('d.pdf', 100, 'application/pdf'),
        'contrato_social' => UploadedFile::fake()->create('e.pdf', 100, 'application/pdf'),
        'estatuto' => UploadedFile::fake()->create('f.pdf', 100, 'application/pdf'),
    ])->assertSessionHasErrors('phone');
});

// Shareholders 100%
it('rejects shareholder total below 100', function () {
    $user = PortalUser::query()->create(['full_name' => 'Share', 'email' => 'share-below@test.com'.uniqid(), 'document_number' => '12345678911', 'phone_number' => '11999999989', 'status' => 'ACTIVE']);
    $this->actingAs($user, 'nimbus')->post(route('nimbus.submissions.store'), [
        'responsible_name' => 'Resp', 'company_cnpj' => validCnpj(), 'company_name' => 'Empresa', 'phone' => '(11) 99999-9999',
        'net_worth' => '1000', 'annual_revenue' => '1000', 'registrant_name' => 'Reg', 'registrant_cpf' => validCpf(),
        'shareholders' => json_encode([['name' => 'A', 'percentage' => 50], ['name' => 'B', 'percentage' => 40]]), 'is_anbima_affiliated' => true,
        'ultimo_balanco' => UploadedFile::fake()->create('a.pdf', 100, 'application/pdf'),
        'dre' => UploadedFile::fake()->create('b.pdf', 100, 'application/pdf'),
        'politicas' => UploadedFile::fake()->create('c.pdf', 100, 'application/pdf'),
        'cartao_cnpj' => UploadedFile::fake()->create('d.pdf', 100, 'application/pdf'),
        'contrato_social' => UploadedFile::fake()->create('e.pdf', 100, 'application/pdf'),
        'estatuto' => UploadedFile::fake()->create('f.pdf', 100, 'application/pdf'),
    ])->assertSessionHasErrors('shareholders');
});

it('rejects shareholder total above 100 and invalid percentage', function () {
    $user = PortalUser::query()->create(['full_name' => 'Share', 'email' => 'share-above@test.com'.uniqid(), 'document_number' => '12345678912', 'phone_number' => '11999999988', 'status' => 'ACTIVE']);
    $this->actingAs($user, 'nimbus')->post(route('nimbus.submissions.store'), [
        'responsible_name' => 'Resp', 'company_cnpj' => validCnpj(), 'company_name' => 'Empresa', 'phone' => '(11) 99999-9999',
        'net_worth' => '1000', 'annual_revenue' => '1000', 'registrant_name' => 'Reg', 'registrant_cpf' => validCpf(),
        'shareholders' => json_encode([['name' => 'A', 'percentage' => 150]]), 'is_anbima_affiliated' => true,
        'ultimo_balanco' => UploadedFile::fake()->create('a.pdf', 100, 'application/pdf'),
        'dre' => UploadedFile::fake()->create('b.pdf', 100, 'application/pdf'),
        'politicas' => UploadedFile::fake()->create('c.pdf', 100, 'application/pdf'),
        'cartao_cnpj' => UploadedFile::fake()->create('d.pdf', 100, 'application/pdf'),
        'contrato_social' => UploadedFile::fake()->create('e.pdf', 100, 'application/pdf'),
        'estatuto' => UploadedFile::fake()->create('f.pdf', 100, 'application/pdf'),
    ])->assertSessionHasErrors('shareholders');
});

// Documents
it('enforces every currently required document', function () {
    $user = PortalUser::query()->create(['full_name' => 'Doc', 'email' => 'doc-req@test.com'.uniqid(), 'document_number' => '12345678913', 'phone_number' => '11999999987', 'status' => 'ACTIVE']);
    $this->actingAs($user, 'nimbus')->post(route('nimbus.submissions.store'), [
        'responsible_name' => 'Resp', 'company_cnpj' => validCnpj(), 'company_name' => 'Empresa', 'phone' => '(11) 99999-9999',
        'net_worth' => '1000', 'annual_revenue' => '1000', 'registrant_name' => 'Reg', 'registrant_cpf' => validCpf(),
        'shareholders' => json_encode([['name' => 'Socio', 'percentage' => 100]]), 'is_anbima_affiliated' => true,
        // missing ultimo_balanco
        'dre' => UploadedFile::fake()->create('b.pdf', 100, 'application/pdf'),
        'politicas' => UploadedFile::fake()->create('c.pdf', 100, 'application/pdf'),
        'cartao_cnpj' => UploadedFile::fake()->create('d.pdf', 100, 'application/pdf'),
        'contrato_social' => UploadedFile::fake()->create('e.pdf', 100, 'application/pdf'),
        'estatuto' => UploadedFile::fake()->create('f.pdf', 100, 'application/pdf'),
    ])->assertSessionHasErrors('ultimo_balanco');
});

it('allows optional procuração and ata to remain optional', function () {
    $user = PortalUser::query()->create(['full_name' => 'Doc', 'email' => 'doc-opt@test.com'.uniqid(), 'document_number' => '12345678914', 'phone_number' => '11999999986', 'status' => 'ACTIVE']);
    $this->actingAs($user, 'nimbus')->post(route('nimbus.submissions.store'), [
        'responsible_name' => 'Resp', 'company_cnpj' => validCnpj(), 'company_name' => 'Empresa', 'phone' => '(11) 99999-9999',
        'net_worth' => '1000', 'annual_revenue' => '1000', 'registrant_name' => 'Reg', 'registrant_cpf' => validCpf(),
        'shareholders' => json_encode([['name' => 'Socio', 'percentage' => 100]]), 'is_anbima_affiliated' => true,
        'ultimo_balanco' => UploadedFile::fake()->create('a.pdf', 100, 'application/pdf'),
        'dre' => UploadedFile::fake()->create('b.pdf', 100, 'application/pdf'),
        'politicas' => UploadedFile::fake()->create('c.pdf', 100, 'application/pdf'),
        'cartao_cnpj' => UploadedFile::fake()->create('d.pdf', 100, 'application/pdf'),
        'contrato_social' => UploadedFile::fake()->create('e.pdf', 100, 'application/pdf'),
        'estatuto' => UploadedFile::fake()->create('f.pdf', 100, 'application/pdf'),
    ])->assertRedirect();
});

it('rejects invalid file type for initial submission', function () {
    $user = PortalUser::query()->create(['full_name' => 'Doc', 'email' => 'doc-type@test.com'.uniqid(), 'document_number' => '12345678915', 'phone_number' => '11999999985', 'status' => 'ACTIVE']);
    $this->actingAs($user, 'nimbus')->post(route('nimbus.submissions.store'), [
        'responsible_name' => 'Resp', 'company_cnpj' => validCnpj(), 'company_name' => 'Empresa', 'phone' => '(11) 99999-9999',
        'net_worth' => '1000', 'annual_revenue' => '1000', 'registrant_name' => 'Reg', 'registrant_cpf' => validCpf(),
        'shareholders' => json_encode([['name' => 'Socio', 'percentage' => 100]]), 'is_anbima_affiliated' => true,
        'ultimo_balanco' => UploadedFile::fake()->create('a.exe', 100, 'application/octet-stream'),
        'dre' => UploadedFile::fake()->create('b.pdf', 100, 'application/pdf'),
        'politicas' => UploadedFile::fake()->create('c.pdf', 100, 'application/pdf'),
        'cartao_cnpj' => UploadedFile::fake()->create('d.pdf', 100, 'application/pdf'),
        'contrato_social' => UploadedFile::fake()->create('e.pdf', 100, 'application/pdf'),
        'estatuto' => UploadedFile::fake()->create('f.pdf', 100, 'application/pdf'),
    ])->assertSessionHasErrors('ultimo_balanco');
});

// Financial
it('rejects negative financial values', function () {
    $user = PortalUser::query()->create(['full_name' => 'Fin', 'email' => 'fin-neg@test.com'.uniqid(), 'document_number' => '12345678916', 'phone_number' => '11999999984', 'status' => 'ACTIVE']);
    $this->actingAs($user, 'nimbus')->post(route('nimbus.submissions.store'), [
        'responsible_name' => 'Resp', 'company_cnpj' => validCnpj(), 'company_name' => 'Empresa', 'phone' => '(11) 99999-9999',
        'net_worth' => '-100', 'annual_revenue' => '1000', 'registrant_name' => 'Reg', 'registrant_cpf' => validCpf(),
        'shareholders' => json_encode([['name' => 'Socio', 'percentage' => 100]]), 'is_anbima_affiliated' => true,
        'ultimo_balanco' => UploadedFile::fake()->create('a.pdf', 100, 'application/pdf'),
        'dre' => UploadedFile::fake()->create('b.pdf', 100, 'application/pdf'),
        'politicas' => UploadedFile::fake()->create('c.pdf', 100, 'application/pdf'),
        'cartao_cnpj' => UploadedFile::fake()->create('d.pdf', 100, 'application/pdf'),
        'contrato_social' => UploadedFile::fake()->create('e.pdf', 100, 'application/pdf'),
        'estatuto' => UploadedFile::fake()->create('f.pdf', 100, 'application/pdf'),
    ])->assertSessionHasErrors('net_worth');
});
