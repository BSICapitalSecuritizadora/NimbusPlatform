<?php

use App\Mail\Nimbus\SendPortalAccessCode;
use App\Models\Nimbus\PortalUser;
use Illuminate\Support\Carbon;

it('renders the portal access link and generated access key', function () {
    config([
        'nimbus.mail.from.address' => 'documentos@example.test',
        'nimbus.mail.from.name' => 'Gestão Documental Externa',
    ]);

    $mail = new SendPortalAccessCode(
        user: new PortalUser([
            'full_name' => 'Cliente Portal',
            'email' => 'cliente.portal@example.test',
        ]),
        code: 'ABCD-1234-EF56',
        accessUrl: 'https://portal.example.test/gestao-documental-externa/login',
        expiresAt: Carbon::parse('2026-05-11 14:30:00'),
    );

    $mail->assertFrom('documentos@example.test');
    $mail->assertHasSubject('Seu Código de Acesso ao Portal - BSI Capital');
    $mail->assertSeeInHtml('Cliente');
    $mail->assertSeeInHtml('ABCD-1234-EF56');
    $mail->assertSeeInHtml('https://portal.example.test/gestao-documental-externa/login');
    $mail->assertSeeInHtml('11/05/2026');
});
