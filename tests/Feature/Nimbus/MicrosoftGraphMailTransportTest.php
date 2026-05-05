<?php

use App\Mail\Transport\MicrosoftGraphTransport;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;

it('registers the graph mailer transport', function () {
    config([
        'mail.mailers.graph' => [
            'transport' => 'graph',
            'tenant_id' => 'tenant-id',
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'mailbox' => 'documentos@example.test',
        ],
    ]);

    Mail::forgetMailers();

    expect(Mail::mailer('graph')->getSymfonyTransport())
        ->toBeInstanceOf(MicrosoftGraphTransport::class);
});

it('sends mail through Microsoft Graph', function () {
    Http::fake([
        'login.microsoftonline.com/tenant-id/oauth2/v2.0/token' => Http::response([
            'access_token' => 'graph-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ]),
        'graph.microsoft.com/v1.0/users/documentos%40example.test/sendMail' => Http::response(null, 202),
    ]);

    $transport = new MicrosoftGraphTransport(
        tenantId: 'tenant-id',
        clientId: 'client-id',
        clientSecret: 'client-secret',
        mailbox: 'documentos@example.test',
    );

    $message = (new Email)
        ->from('BSI Capital <documentos@example.test>')
        ->to('Cliente Portal <cliente@example.test>')
        ->cc('copia@example.test')
        ->subject('Seu Código de Acesso ao Portal - BSI Capital')
        ->html('<p>Use a chave ABCD-1234-EF56</p>');

    $transport->send($message);

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://login.microsoftonline.com/tenant-id/oauth2/v2.0/token'
            && $request['client_id'] === 'client-id'
            && $request['client_secret'] === 'client-secret'
            && $request['grant_type'] === 'client_credentials'
            && $request['scope'] === 'https://graph.microsoft.com/.default';
    });

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://graph.microsoft.com/v1.0/users/documentos%40example.test/sendMail'
            && $request->hasHeader('Authorization', 'Bearer graph-token')
            && $request['message']['subject'] === 'Seu Código de Acesso ao Portal - BSI Capital'
            && $request['message']['body']['contentType'] === 'HTML'
            && $request['message']['body']['content'] === '<p>Use a chave ABCD-1234-EF56</p>'
            && $request['message']['toRecipients'][0]['emailAddress']['address'] === 'cliente@example.test'
            && $request['message']['ccRecipients'][0]['emailAddress']['address'] === 'copia@example.test'
            && $request['saveToSentItems'] === true;
    });
});
