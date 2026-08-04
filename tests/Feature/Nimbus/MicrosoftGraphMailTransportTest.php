<?php

use App\Mail\Transport\MicrosoftGraphTransport;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;

beforeEach(function (): void {
    Cache::flush();
});

/**
 * @param  array<string, mixed>  $overrides
 */
function graphTransport(array $overrides = []): MicrosoftGraphTransport
{
    return new MicrosoftGraphTransport(
        tenantId: $overrides['tenantId'] ?? 'tenant-id',
        clientId: $overrides['clientId'] ?? 'client-id',
        clientSecret: $overrides['clientSecret'] ?? 'client-secret',
        mailbox: $overrides['mailbox'] ?? 'documentos@example.test',
    );
}

function graphMessage(string $to = 'cliente@example.test'): Email
{
    return (new Email)
        ->from('BSI Capital <documentos@example.test>')
        ->to($to)
        ->subject('Assunto')
        ->html('<p>Conteúdo</p>');
}

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

/**
 * O token era pedido ao Entra ID a cada `doSend()`. Numa fila com muitos
 * e-mails isso multiplica as chamadas ao endpoint de token e pode disparar
 * throttling — derrubando junto os códigos de acesso do portal e os links de
 * proposta.
 */
it('requests the graph access token only once for a batch of messages', function () {
    Http::fake([
        'login.microsoftonline.com/tenant-id/oauth2/v2.0/token' => Http::response([
            'access_token' => 'graph-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ]),
        'graph.microsoft.com/v1.0/users/documentos%40example.test/sendMail' => Http::response(null, 202),
    ]);

    $transport = graphTransport();

    foreach (range(1, 5) as $index) {
        $transport->send(graphMessage("cliente{$index}@example.test"));
    }

    $tokenRequests = collect(Http::recorded())
        ->filter(fn (array $exchange): bool => str_contains($exchange[0]->url(), '/oauth2/v2.0/token'))
        ->count();

    expect($tokenRequests)->toBe(1);
});

it('does not share a cached token between different credentials', function () {
    Http::fake([
        'login.microsoftonline.com/tenant-id/oauth2/v2.0/token' => Http::response([
            'access_token' => 'graph-token',
            'expires_in' => 3600,
        ]),
        'login.microsoftonline.com/outro-tenant/oauth2/v2.0/token' => Http::response([
            'access_token' => 'outro-token',
            'expires_in' => 3600,
        ]),
        'graph.microsoft.com/v1.0/users/*' => Http::response(null, 202),
    ]);

    graphTransport()->send(graphMessage());
    graphTransport(['tenantId' => 'outro-tenant'])->send(graphMessage());

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'graph.microsoft.com')
        && $request->hasHeader('Authorization', 'Bearer graph-token'));

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'graph.microsoft.com')
        && $request->hasHeader('Authorization', 'Bearer outro-token'));
});

it('does not cache the token past the lifetime reported by the identity platform', function () {
    Http::fake([
        'login.microsoftonline.com/tenant-id/oauth2/v2.0/token' => Http::sequence()
            ->push(['access_token' => 'primeiro-token', 'expires_in' => 360])
            ->push(['access_token' => 'segundo-token', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/users/documentos%40example.test/sendMail' => Http::response(null, 202),
    ]);

    $transport = graphTransport();

    // expires_in 360s menos a margem de 300s deixa 60s de cache.
    $transport->send(graphMessage());

    $this->travel(61)->seconds();

    $transport->send(graphMessage());

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'graph.microsoft.com')
        && $request->hasHeader('Authorization', 'Bearer segundo-token'));
});
