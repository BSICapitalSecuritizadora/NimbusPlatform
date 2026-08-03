<?php

namespace App\Providers;

use App\Domain\PuCalculator\Contracts\BusinessDayCalendar;
use App\Domain\PuCalculator\Contracts\IndexRateProvider;
use App\Domain\PuCalculator\Services\BusinessCalendarService;
use App\Domain\PuCalculator\Services\BusinessDayCalendarService;
use App\Domain\PuCalculator\Services\DecimalRounder;
use App\Domain\PuCalculator\Services\IndexRateLookupService;
use App\Domain\PuCalculator\Services\IndexRateService;
use App\Domain\PuCalculator\Services\RoundingService;
use App\Listeners\LogNotificationListener;
use App\Mail\Transport\MicrosoftGraphTransport;
use App\Models\Document;
use App\Models\Nimbus\Submission;
use App\Policies\DocumentPolicy;
use App\Policies\Nimbus\SubmissionPolicy;
use App\Services\ConstructionProgressProvider;
use App\Services\MeasurementPlanProgressProvider;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use SocialiteProviders\Azure\Provider as AzureProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            ConstructionProgressProvider::class,
            MeasurementPlanProgressProvider::class,
        );

        $this->app->singleton(RoundingService::class);
        $this->app->alias(RoundingService::class, DecimalRounder::class);

        $this->app->singleton(BusinessDayCalendarService::class);
        $this->app->alias(BusinessDayCalendarService::class, BusinessCalendarService::class);
        $this->app->bind(BusinessDayCalendar::class, BusinessDayCalendarService::class);

        $this->app->singleton(IndexRateService::class);
        $this->app->alias(IndexRateService::class, IndexRateLookupService::class);
        $this->app->bind(IndexRateProvider::class, IndexRateService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->assertRedisIsHardened();
        $this->configureRateLimiting();
        $this->configureMacros();
        $this->configureMailTransports();

        Gate::policy(Submission::class, SubmissionPolicy::class);
        Gate::policy(Document::class, DocumentPolicy::class);

        Gate::before(function ($user, $ability) {
            return (method_exists($user, 'hasRole') && $user->hasRole('super-admin')) ? true : null;
        });

        Paginator::useBootstrapFive();

        Event::listen(function (SocialiteWasCalled $event): void {
            $event->extendSocialite('azure', AzureProvider::class);
        });

        Event::listen(function (Login $event): void {
            activity('login')
                ->causedBy($event->user)
                ->withProperties(['guard' => $event->guard, 'ip' => request()->ip()])
                ->log('login');
        });

        Event::listen(function (Logout $event): void {
            if ($event->user === null) {
                return;
            }

            activity('logout')
                ->causedBy($event->user)
                ->withProperties(['guard' => $event->guard, 'ip' => request()->ip()])
                ->log('logout');
        });

        Event::listen(
            NotificationSent::class,
            [LogNotificationListener::class, 'handleSent']
        );

        Event::listen(
            NotificationFailed::class,
            [LogNotificationListener::class, 'handleFailed']
        );
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        if (app()->isProduction()) {
            URL::forceScheme('https');

            // Abort early to prevent stack-trace/query disclosure in production.
            abort_if(config('app.debug'), 500, 'APP_DEBUG must be false in production.');
        }

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Sessão, cache e fila autenticadas não podem trafegar num Redis remoto sem
     * senha ou em texto claro: quem alcançar a porta lê e reescreve sessões de
     * administrador. O template de produção já aponta para TLS na 6380, mas o
     * valor real vem de App Settings — por isso a checagem é feita no boot.
     *
     * Conexões em loopback (sidecar/socket) ficam de fora: não estão expostas
     * na rede e quebrar esse cenário não traria ganho de segurança.
     */
    protected function assertRedisIsHardened(): void
    {
        if (! app()->isProduction() || ! $this->usesRedis()) {
            return;
        }

        foreach ((array) config('database.redis') as $name => $connection) {
            if (! is_array($connection) || ! array_key_exists('host', $connection)) {
                continue;
            }

            $connection = (new ConfigurationUrlParser)->parseConfiguration($connection);
            $driver = strtolower((string) ($connection['driver'] ?? ''));
            $scheme = in_array($driver, ['tcp', 'tls'], true)
                ? $driver
                : (string) ($connection['scheme'] ?? 'tcp');

            if ($this->isLoopbackRedisHost((string) ($connection['host'] ?? ''))) {
                continue;
            }

            abort_if(
                blank($connection['password'] ?? null),
                500,
                "Redis connection [{$name}] is exposed without a password. Set REDIS_PASSWORD in production.",
            );

            abort_if(
                $scheme !== 'tls',
                500,
                "Redis connection [{$name}] would send session data in cleartext. Set REDIS_SCHEME=tls in production.",
            );
        }
    }

    protected function usesRedis(): bool
    {
        return config('cache.default') === 'redis'
            || config('session.driver') === 'redis'
            || config('queue.default') === 'redis';
    }

    protected function isLoopbackRedisHost(string $host): bool
    {
        $host = Str::of($host)->after('://')->trim()->toString();

        return str_starts_with($host, '/')
            || in_array($host, ['127.0.0.1', 'localhost', '::1', '[::1]'], true);
    }

    protected function configureMacros(): void
    {
        Str::macro('digitsOnly', fn (string $value): string => preg_replace('/\D/', '', $value) ?? '');
    }

    protected function configureMailTransports(): void
    {
        Mail::extend('graph', fn (array $config): MicrosoftGraphTransport => new MicrosoftGraphTransport(
            tenantId: (string) ($config['tenant_id'] ?? config('services.outlook.tenant_id')),
            clientId: (string) ($config['client_id'] ?? config('services.outlook.client_id')),
            clientSecret: (string) ($config['client_secret'] ?? config('services.outlook.client_secret')),
            mailbox: (string) ($config['mailbox'] ?? config('services.outlook.mailbox')),
            saveToSentItems: (bool) ($config['save_to_sent_items'] ?? true),
            timeout: (int) ($config['timeout'] ?? 30),
            graphBaseUrl: (string) ($config['base_url'] ?? 'https://graph.microsoft.com/v1.0'),
        ));
    }

    protected function configureRateLimiting(): void
    {
        RateLimiter::for('site-contact', function (Request $request): array {
            return [
                Limit::perMinutes(60, 5)->by("site-contact|ip|{$request->ip()}"),
                Limit::perDay(200)->by('site-contact|global'),
            ];
        });

        RateLimiter::for('proposal-link-access', function (Request $request): Limit {
            $access = $request->route('access');
            $token = is_object($access) && method_exists($access, 'getRouteKey')
                ? (string) $access->getRouteKey()
                : (string) $access;

            return Limit::perMinute(20)->by("proposal-link-access|{$request->ip()}|{$token}");
        });

        RateLimiter::for('proposal-verification', function (Request $request): Limit {
            $access = $request->route('access');
            $token = is_object($access) && method_exists($access, 'getRouteKey')
                ? (string) $access->getRouteKey()
                : (string) $access;

            return Limit::perMinute(5)->by("proposal-verification|{$request->ip()}|{$token}");
        });

        RateLimiter::for('proposal-continuation-store', function (Request $request): Limit {
            $access = $request->route('access');
            $token = is_object($access) && method_exists($access, 'getRouteKey')
                ? (string) $access->getRouteKey()
                : (string) $access;

            return Limit::perMinute(10)->by("proposal-continuation-store|{$request->ip()}|{$token}");
        });

        // C-1: throttle for file downloads in the proposal continuation flow
        RateLimiter::for('proposal-continuation-download', function (Request $request): Limit {
            $access = $request->route('access');
            $token = is_object($access) && method_exists($access, 'getRouteKey')
                ? (string) $access->getRouteKey()
                : (string) $access;

            return Limit::perMinute(20)->by("proposal-continuation-download|{$request->ip()}|{$token}");
        });

        // C-2: global per-token rate limit regardless of IP to block distributed brute force on the 6-digit code
        RateLimiter::for('proposal-verification-global', function (Request $request): Limit {
            $access = $request->route('access');
            $token = is_object($access) && method_exists($access, 'getRouteKey')
                ? (string) $access->getRouteKey()
                : (string) $access;

            return Limit::perDay(15)->by("proposal-verification-global|{$token}");
        });

        // C-3: rate limit for public job applications to prevent resume spam
        RateLimiter::for('site-job-apply', function (Request $request): Limit {
            return Limit::perMinutes(30, 5)->by("site-job-apply|{$request->ip()}");
        });

        // Nimbus portal login: daily IP budget so code-rotating attackers are still blocked
        RateLimiter::for('nimbus-access-code', function (Request $request): Limit {
            return Limit::perDay(50)->by("nimbus-access-code|{$request->ip()}");
        });
    }
}
