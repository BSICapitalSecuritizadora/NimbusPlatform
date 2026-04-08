<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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

        Gate::before(function ($user, $ability) {
            return $user->hasRole('super-admin') ? true : null;
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
