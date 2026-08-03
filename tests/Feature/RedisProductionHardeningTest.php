<?php

use App\Providers\AppServiceProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * @param  array<string, mixed>  $connection
 */
function redisConfig(array $connection = []): array
{
    return [
        'client' => 'phpredis',
        'options' => ['cluster' => 'redis', 'prefix' => 'bsi-'],
        'default' => array_merge([
            'url' => null,
            'scheme' => 'tls',
            'host' => 'bsi-cache.redis.cache.windows.net',
            'username' => null,
            'password' => 'access-key',
            'port' => '6380',
            'database' => '0',
        ], $connection),
    ];
}

function bootRedisGuard(): void
{
    $provider = new AppServiceProvider(app());

    (new ReflectionMethod($provider, 'assertRedisIsHardened'))->invoke($provider);
}

beforeEach(function () {
    $this->app['env'] = 'production';

    config([
        'cache.default' => 'redis',
        'session.driver' => 'redis',
        'queue.default' => 'redis',
    ]);
});

// ── M-14: Redis sem senha / sem TLS em produção ──────────────────────────────

it('refuses to boot production with a passwordless remote redis (M-14)', function (mixed $password) {
    config(['database.redis' => redisConfig(['password' => $password])]);

    expect(bootRedisGuard(...))
        ->toThrow(HttpException::class, 'exposed without a password');
})->with([
    'null' => null,
    'empty string' => '',
    'blank string' => '   ',
]);

it('refuses to boot production with a cleartext remote redis (M-14)', function (mixed $scheme) {
    config(['database.redis' => redisConfig(['scheme' => $scheme, 'port' => '6379'])]);

    expect(bootRedisGuard(...))
        ->toThrow(HttpException::class, 'cleartext');
})->with([
    'tcp' => 'tcp',
    'unset' => null,
]);

it('boots production with an authenticated TLS redis (M-14)', function () {
    config(['database.redis' => redisConfig()]);

    expect(bootRedisGuard(...))->not->toThrow(HttpException::class);
});

it('accepts a rediss:// url as TLS (M-14)', function () {
    config(['database.redis' => redisConfig([
        'url' => 'rediss://:access-key@bsi-cache.redis.cache.windows.net:6380',
        'scheme' => 'tcp',
        'password' => null,
    ])]);

    expect(bootRedisGuard(...))->not->toThrow(HttpException::class);
});

it('rejects a redis:// url even when the connection declares TLS (M-14)', function () {
    config(['database.redis' => redisConfig([
        'url' => 'redis://:access-key@bsi-cache.redis.cache.windows.net:6379',
        'scheme' => 'tls',
    ])]);

    expect(bootRedisGuard(...))
        ->toThrow(HttpException::class, 'cleartext');
});

it('leaves loopback redis alone, since it is not reachable from the network (M-14)', function (string $host) {
    config(['database.redis' => redisConfig([
        'host' => $host,
        'scheme' => 'tcp',
        'password' => null,
        'port' => '6379',
    ])]);

    expect(bootRedisGuard(...))->not->toThrow(HttpException::class);
})->with([
    'ipv4 loopback' => '127.0.0.1',
    'localhost' => 'localhost',
    'ipv6 loopback' => '::1',
    'unix socket' => '/var/run/redis/redis.sock',
]);

it('stays quiet when redis backs neither cache, session nor queue (M-14)', function () {
    config([
        'cache.default' => 'database',
        'session.driver' => 'database',
        'queue.default' => 'database',
        'database.redis' => redisConfig(['scheme' => 'tcp', 'password' => null]),
    ]);

    expect(bootRedisGuard(...))->not->toThrow(HttpException::class);
});

it('does not block non-production environments (M-14)', function () {
    $this->app['env'] = 'local';

    config(['database.redis' => redisConfig(['scheme' => 'tcp', 'password' => null])]);

    expect(bootRedisGuard(...))->not->toThrow(HttpException::class);
});

// ── M-14: template de produção ───────────────────────────────────────────────

it('ships a production env template that points at an authenticated TLS redis (M-14)', function () {
    $template = file_get_contents(base_path('.env.example.production'));

    expect($template)
        ->toContain('REDIS_SCHEME=tls')
        ->toContain('REDIS_PORT=6380')
        ->toContain('REDIS_PASSWORD=')
        ->not->toContain('REDIS_PASSWORD=null')
        ->not->toContain('REDIS_HOST=127.0.0.1')
        ->not->toContain('REDIS_PORT=6379');
});

it('lets REDIS_SCHEME reach every redis connection (M-14)', function () {
    $connections = require base_path('config/database.php');

    expect($connections['redis']['default'])->toHaveKey('scheme')
        ->and($connections['redis']['cache'])->toHaveKey('scheme');
});
