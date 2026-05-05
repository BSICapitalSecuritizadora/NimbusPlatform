<?php

namespace App\Providers;

use App\Mail\Transport\MicrosoftGraphTransport;
use App\Models\Document;
use App\Models\Nimbus\Submission;
use App\Policies\DocumentPolicy;
use App\Policies\Nimbus\SubmissionPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
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
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        if (app()->isProduction()) {
            URL::forceScheme('https');
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
        RateLimiter::for('proposal-submission', function (Request $request): Limit {
            $email = mb_strtolower((string) $request->input('email'));
            $cnpj = Str::digitsOnly((string) $request->input('cnpj'));

            return Limit::perMinute(5)->by(implode('|', [
                'proposal-submission',
                $request->ip(),
                $email,
                $cnpj,
            ]));
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
    }
}
