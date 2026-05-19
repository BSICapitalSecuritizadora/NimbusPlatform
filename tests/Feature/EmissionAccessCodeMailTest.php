<?php

use App\Mail\EmissionAccessCodeMail;
use App\Models\Emission;
use App\Models\EmissionAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the emission access mail with the secure link and validation code', function () {
    config([
        'nimbus.mail.from.address' => 'documentos@example.test',
        'nimbus.mail.from.name' => 'Gestão Documental Externa',
        'mail.from.address' => 'fallback@example.test',
        'mail.from.name' => 'Fallback Mailer',
    ]);

    $emission = Emission::factory()->active()->create([
        'name' => 'CRI Dom Aloysio',
        'if_code' => '26C0589381',
        'issuer' => 'BSI Capital Securitizadora',
        'maturity_date' => '2031-03-20',
        'is_public' => true,
    ]);

    $access = EmissionAccess::factory()->for($emission)->make([
        'requester_name' => 'Maria da Silva',
        'expires_at' => now()->addDays(7)->setTime(14, 30),
    ]);

    $mail = new EmissionAccessCodeMail(
        emission: $emission,
        access: $access,
        code: '123456',
        accessUrl: 'https://site.example.test/emissoes/acesso/token-teste',
    );

    $mail->assertFrom('documentos@example.test');
    $mail->assertHasSubject('Seu código de acesso à operação - BSI Capital');
    $mail->assertSeeInHtml('CRI Dom Aloysio');
    $mail->assertSeeInHtml('123456');
    $mail->assertSeeInHtml('26C0589381');
    $mail->assertSeeInHtml('Gestão Documental Externa');
    $mail->assertSeeInHtml('https://site.example.test/emissoes/acesso/token-teste');
    $mail->assertSeeInHtml('14:30');
});
