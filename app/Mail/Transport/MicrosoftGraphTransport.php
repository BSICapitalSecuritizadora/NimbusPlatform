<?php

namespace App\Mail\Transport;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;
use Symfony\Component\Mime\Part\DataPart;

class MicrosoftGraphTransport extends AbstractTransport
{
    /**
     * Margem descontada do `expires_in` devolvido pelo Entra ID, para que o
     * token nunca seja usado no exato instante em que expira.
     */
    private const TOKEN_EXPIRY_LEEWAY_SECONDS = 300;

    private const TOKEN_MAX_CACHE_SECONDS = 3300;

    public function __construct(
        private string $tenantId,
        private string $clientId,
        private string $clientSecret,
        private string $mailbox,
        private bool $saveToSentItems = true,
        private int $timeout = 30,
        private string $graphBaseUrl = 'https://graph.microsoft.com/v1.0',
    ) {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $this->ensureConfigured();

        $email = MessageConverter::toEmail($message->getOriginalMessage());

        $response = Http::timeout($this->timeout)
            ->acceptJson()
            ->withToken($this->getAccessToken())
            ->post($this->sendMailUrl(), $this->buildPayload($email, $message->getEnvelope()));

        if (! $response->accepted()) {
            throw new TransportException(
                sprintf('Request to Microsoft Graph sendMail failed with status [%s]: %s', $response->status(), $response->body()),
                $response->status(),
            );
        }
    }

    public function __toString(): string
    {
        return 'microsoft-graph';
    }

    /**
     * Tokens `client_credentials` valem cerca de uma hora, mas eram pedidos ao
     * Entra ID a cada mensagem: uma fila com muitos e-mails multiplicava as
     * chamadas ao endpoint de token e podia ser barrada por throttling, levando
     * junto os códigos de acesso do portal e os links de proposta.
     *
     * O token fica em cache pelo tempo que o próprio Entra ID informa em
     * `expires_in`, com margem de segurança para o relógio e para o tempo de
     * envio, e limitado a 55 minutos.
     */
    private function getAccessToken(): string
    {
        $cacheKey = $this->accessTokenCacheKey();
        $cachedToken = Cache::get($cacheKey);

        if (is_string($cachedToken) && $cachedToken !== '') {
            return $cachedToken;
        }

        $token = $this->requestAccessToken();

        Cache::put($cacheKey, $token['access_token'], $this->cacheableTokenLifetime($token));

        return $token['access_token'];
    }

    /**
     * @return array{access_token: string, expires_in: int}
     */
    private function requestAccessToken(): array
    {
        $response = Http::asForm()
            ->timeout($this->timeout)
            ->post($this->tokenUrl(), [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'grant_type' => 'client_credentials',
                'scope' => 'https://graph.microsoft.com/.default',
            ]);

        if (! $response->successful()) {
            throw new TransportException(
                sprintf('Request to Microsoft identity platform failed with status [%s]: %s', $response->status(), $response->body()),
                $response->status(),
            );
        }

        $accessToken = $response->json('access_token');

        if (blank($accessToken)) {
            throw new TransportException('Microsoft identity platform did not return an access token.');
        }

        return [
            'access_token' => (string) $accessToken,
            'expires_in' => (int) $response->json('expires_in', 0),
        ];
    }

    /**
     * @param  array{access_token: string, expires_in: int}  $token
     */
    private function cacheableTokenLifetime(array $token): int
    {
        $lifetime = $token['expires_in'] - self::TOKEN_EXPIRY_LEEWAY_SECONDS;

        return max(60, min($lifetime, self::TOKEN_MAX_CACHE_SECONDS));
    }

    /**
     * A chave carrega o tenant, o client e a caixa: transports diferentes no
     * mesmo processo não podem compartilhar o token um do outro. O segredo não
     * entra na chave — só o que identifica a credencial.
     */
    private function accessTokenCacheKey(): string
    {
        return 'msgraph:token:'.hash('sha256', implode('|', [
            $this->tenantId,
            $this->clientId,
            $this->mailbox,
        ]));
    }

    /**
     * @return array{
     *     message: array<string, mixed>,
     *     saveToSentItems: bool
     * }
     */
    private function buildPayload(Email $email, Envelope $envelope): array
    {
        $payload = [
            'message' => array_filter([
                'subject' => $email->getSubject() ?? '',
                'body' => [
                    'contentType' => $email->getHtmlBody() !== null ? 'HTML' : 'Text',
                    'content' => (string) ($email->getHtmlBody() ?? $email->getTextBody() ?? ''),
                ],
                'toRecipients' => $this->mapRecipients($this->getDirectRecipients($email, $envelope)),
                'ccRecipients' => $this->mapRecipients($email->getCc()),
                'bccRecipients' => $this->mapRecipients($email->getBcc()),
                'replyTo' => $this->mapRecipients($email->getReplyTo()),
                'attachments' => $this->mapAttachments($email->getAttachments()),
            ], fn (mixed $value): bool => $value !== []),
            'saveToSentItems' => $this->saveToSentItems,
        ];

        return $payload;
    }

    /**
     * @param  array<int, Address>  $addresses
     * @return array<int, array{emailAddress: array{address: string, name?: string}}>
     */
    private function mapRecipients(array $addresses): array
    {
        return array_map(
            static fn (Address $address): array => [
                'emailAddress' => array_filter([
                    'address' => $address->getAddress(),
                    'name' => $address->getName(),
                ], static fn (?string $value): bool => filled($value)),
            ],
            $addresses,
        );
    }

    /**
     * @param  array<int, DataPart>  $attachments
     * @return array<int, array<string, mixed>>
     */
    private function mapAttachments(array $attachments): array
    {
        return array_map(static function (DataPart $attachment): array {
            $headers = $attachment->getPreparedHeaders();

            return array_filter([
                '@odata.type' => '#microsoft.graph.fileAttachment',
                'name' => $attachment->getFilename() ?? $headers->getHeaderParameter('Content-Type', 'name') ?? 'attachment',
                'contentType' => $attachment->getContentType(),
                'contentBytes' => base64_encode($attachment->getBody()),
                'isInline' => $attachment->getDisposition() === 'inline',
                'contentId' => $attachment->hasContentId() ? $attachment->getContentId() : null,
            ], static fn (mixed $value): bool => $value !== null);
        }, $attachments);
    }

    /**
     * @return array<int, Address>
     */
    private function getDirectRecipients(Email $email, Envelope $envelope): array
    {
        return array_values(array_filter(
            $envelope->getRecipients(),
            static fn (Address $address): bool => ! in_array($address, array_merge($email->getCc(), $email->getBcc()), true),
        ));
    }

    private function ensureConfigured(): void
    {
        $missing = array_keys(array_filter([
            'OUTLOOK_TENANT_ID' => $this->tenantId,
            'OUTLOOK_CLIENT_ID' => $this->clientId,
            'OUTLOOK_CLIENT_SECRET' => $this->clientSecret,
            'OUTLOOK_MAILBOX' => $this->mailbox,
        ], static fn (string $value): bool => blank($value)));

        if ($missing !== []) {
            throw new TransportException('Microsoft Graph mail transport is missing configuration: '.implode(', ', $missing).'.');
        }
    }

    private function tokenUrl(): string
    {
        return sprintf('https://login.microsoftonline.com/%s/oauth2/v2.0/token', rawurlencode($this->tenantId));
    }

    private function sendMailUrl(): string
    {
        return rtrim($this->graphBaseUrl, '/').'/users/'.rawurlencode($this->mailbox).'/sendMail';
    }
}
