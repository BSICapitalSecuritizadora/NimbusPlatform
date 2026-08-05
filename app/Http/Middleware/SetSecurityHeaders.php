<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Foundation\Vite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class SetSecurityHeaders
{
    /**
     * Áreas que renderizam Alpine (painel Filament e telas Livewire + Flux) e
     * por isso ainda dependem de `'unsafe-eval'` para avaliar expressões `x-*`.
     *
     * Tudo que não estiver nesta lista — incluindo todo o site público — recebe
     * uma CSP sem `'unsafe-eval'`.
     *
     * @var list<string>
     */
    /**
     * O painel Filament não emite `nonce` nos scripts que ele próprio injeta —
     * a versão instalada não expõe API para isso.
     *
     * Pela especificação da CSP, a presença de um `nonce-source` na política faz
     * o `'unsafe-inline'` ser IGNORADO. Enviar nonce aqui, portanto, não protege
     * nada: apenas bloqueia os scripts do painel e o deixa inutilizável. Nestas
     * rotas a política sai sem nonce, e o `'unsafe-inline'` volta a valer.
     *
     * A troca é aceitável porque o painel exige autenticação, aprovação e 2FA,
     * enquanto o site público — onde o visitante é anônimo e a CSP realmente
     * importa — mantém nonce e continua sem `'unsafe-eval'`.
     *
     * Revisar quando o Filament passar a suportar nonce.
     *
     * @var list<string>
     */
    private const ROUTES_WITHOUT_NONCE = [
        'filament.*',
    ];

    private const UNSAFE_EVAL_ROUTES = [
        'filament.admin.*',
        'investor.*',
        'proposal.*',
        'site.proposal.continuation.*',
        'dashboard',
        'pending-approval',
        'login',
        'register',
        'password.*',
        'verification.*',
        'two-factor.*',
        'profile.edit',
        'appearance.edit',
    ];

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($httpsRedirect = $this->httpsRedirect($request)) {
            return $httpsRedirect;
        }

        $nonce = $this->shouldUseNonce($request) ? Str::random(16) : null;

        if ($nonce !== null) {
            app(Vite::class)->useCspNonce($nonce);
        }

        $response = $next($request);
        $allowUnsafeEval = $this->shouldAllowUnsafeEval($request);

        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-XSS-Protection', '0');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');

        if ($request->user() || $request->user('investor') || $request->user('nimbus')) {
            $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private, max-age=0');
            $response->headers->set('Pragma', 'no-cache');
        }

        // Respostas que servem arquivos definem a própria CSP (mais restritiva);
        // a política global não deve sobrescrevê-la.
        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', $this->buildCsp($nonce, $allowUnsafeEval));
        }

        if (app()->isProduction()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    /**
     * Defense in depth for the App Service "HTTPS Only" toggle: `URL::forceScheme()`
     * only affects URL generation, it does not redirect inbound plain HTTP requests.
     *
     * A request carrying `X-Forwarded-Proto: https` is treated as already secure even
     * when the proxy is not trusted, so a missing `TRUSTED_PROXIES` on the App Service
     * cannot turn this into a redirect loop.
     */
    private function httpsRedirect(Request $request): ?RedirectResponse
    {
        if (! app()->isProduction() || ! config('app.force_https_redirect')) {
            return null;
        }

        if ($request->isSecure() || $this->forwardedProtocol($request) === 'https') {
            return null;
        }

        // Platform health probes and warmup pings run over plain HTTP and expect a 2xx.
        if ($request->is('up', 'healthcheck')) {
            return null;
        }

        return redirect()->secure(
            $request->getRequestUri(),
            $request->isMethodSafe() ? 301 : 308,
        );
    }

    private function forwardedProtocol(Request $request): string
    {
        return Str::of((string) $request->headers->get('X-Forwarded-Proto'))
            ->before(',')
            ->trim()
            ->lower()
            ->value();
    }

    private function shouldAllowUnsafeEval(Request $request): bool
    {
        return $request->routeIs(...self::UNSAFE_EVAL_ROUTES);
    }

    private function shouldUseNonce(Request $request): bool
    {
        return ! $request->routeIs(...self::ROUTES_WITHOUT_NONCE);
    }

    /**
     * @param  string|null  $nonce  `null` mantém o `'unsafe-inline'` efetivo — ver
     *                              {@see self::ROUTES_WITHOUT_NONCE}.
     */
    private function buildCsp(?string $nonce, bool $allowUnsafeEval = false): string
    {
        $scriptSources = [
            "'self'",
            "'unsafe-inline'",
            'https://*.clarity.ms',
        ];

        if ($nonce !== null) {
            $scriptSources[] = "'nonce-{$nonce}'";
        }

        if ($this->shouldAllowViteDevServerSources()) {
            array_push(
                $scriptSources,
                'http://localhost:5173',
                'http://127.0.0.1:5173',
            );
        }

        if ($allowUnsafeEval) {
            $scriptSources[] = "'unsafe-eval'";
        }

        $styleSources = [
            "'self'",
            "'unsafe-inline'",
            'https://fonts.googleapis.com',
        ];

        if ($this->shouldAllowViteDevServerSources()) {
            array_push(
                $styleSources,
                'http://localhost:5173',
                'http://127.0.0.1:5173',
            );
        }

        $fontSources = implode(' ', [
            "'self'",
            'data:',
            'https://fonts.gstatic.com',
        ]);

        $connectSources = [
            "'self'",
            'https://fonts.googleapis.com',
            'https://fonts.gstatic.com',
            'https://*.clarity.ms',
            'wss:',
            'ws:',
        ];

        if ($this->shouldAllowViteDevServerSources()) {
            array_push(
                $connectSources,
                'http://localhost:5173',
                'http://127.0.0.1:5173',
                'ws://localhost:5173',
                'ws://127.0.0.1:5173',
            );
        }

        return implode('; ', [
            "default-src 'self'",
            'script-src '.implode(' ', array_unique($scriptSources)),
            'style-src '.implode(' ', array_unique($styleSources)),
            "img-src 'self' data: blob: https:",
            "font-src {$fontSources}",
            'connect-src '.implode(' ', array_unique($connectSources)),
            // O único iframe de terceiro é o mapa embutido na página de contato
            // (resources/views/site/contact.blade.php). O caminho restringe a
            // origem ao endpoint de embed — não é entrada morta, não remover.
            "frame-src 'self' https://www.google.com/maps/embed",
            "frame-ancestors 'self'",
            "form-action 'self'",
            "base-uri 'self'",
            "object-src 'none'",
        ]);
    }

    private function shouldAllowViteDevServerSources(): bool
    {
        return app()->runningUnitTests() || app(Vite::class)->isRunningHot();
    }
}
