<?php

use Illuminate\Console\CommandMutex;
use Illuminate\Support\Facades\File;

it('configures the Azure startup script to raise the nginx body size limit', function () {
    $startupScriptPath = base_path('startup.sh');

    expect(File::exists($startupScriptPath))->toBeTrue();

    $startupScript = File::get($startupScriptPath);

    expect($startupScript)->toContain('NGINX_CLIENT_MAX_BODY_SIZE')
        ->and($startupScript)->toContain('client_max_body_size')
        ->and($startupScript)->toContain('/etc/nginx/sites-enabled/default')
        ->and($startupScript)->toContain('/etc/nginx/sites-available/default')
        ->and($startupScript)->toContain('/home/site/wwwroot/public')
        ->and($startupScript)->toContain('try_files \$uri \$uri/ /index.php?\$query_string;')
        ->and($startupScript)->toContain('php artisan migrate --force --isolated --no-interaction')
        ->and($startupScript)->toContain('php artisan optimize')
        ->and($startupScript)->toContain('service nginx reload || service nginx restart || true');
});

it('redirects forwarded http traffic to https at the nginx layer', function () {
    $startupScript = File::get(base_path('startup.sh'));

    expect($startupScript)
        ->toContain('if (\$http_x_forwarded_proto = "http") {')
        ->toContain('return 301 https://\$host\$request_uri;')
        // "!= https" redirecionaria sondas internas sem o cabeçalho, criando loop.
        ->not->toContain('\$http_x_forwarded_proto != "https"');
});

it('reloads nginx before the migrations so the health check path answers during boot', function () {
    $startupLines = preg_split('/\R/', File::get(base_path('startup.sh')));

    $firstLineMatching = function (string $pattern) use ($startupLines): ?int {
        foreach ($startupLines as $index => $line) {
            if (preg_match($pattern, $line) === 1) {
                return $index;
            }
        }

        return null;
    };

    $reloadLine = $firstLineMatching('/^\s*service nginx reload\b/');
    $migrateLine = $firstLineMatching('/^\s*php artisan migrate\b/');

    expect($reloadLine)->not->toBeNull()
        ->and($migrateLine)->not->toBeNull()
        // O nginx da imagem serve /home/site/wwwroot até o reload aplicar o
        // docroot correto. Recarregar depois do migrate faz todo caminho —
        // inclusive /up — responder 404 durante o boot, e a sonda de
        // integridade do App Service reprova a instância.
        ->and($reloadLine)->toBeLessThan($migrateLine);
});

it('runs container migrations once under a distributed lock', function () {
    $startupLines = preg_split('/\R/', File::get(base_path('startup.sh')));
    $migrationCommands = array_values(array_filter(
        $startupLines,
        fn (string $line): bool => preg_match('/^\s*php artisan migrate\b/', $line) === 1,
    ));

    expect($migrationCommands)->toBe(['php artisan migrate --force --isolated --no-interaction'])
        ->and(File::get(base_path('.env.example.production')))->toContain('CACHE_STORE=redis');
});

it('logs and skips migrations when another container owns the migration lock', function () {
    $commandMutex = Mockery::mock(CommandMutex::class);
    $commandMutex->shouldReceive('create')->once()->andReturnFalse();
    $commandMutex->shouldNotReceive('forget');

    app()->instance(CommandMutex::class, $commandMutex);

    $this->artisan('migrate --force --isolated --no-interaction')
        ->expectsOutputToContain('The [migrate] command is already running.')
        ->assertSuccessful();
});

it('keeps the queue worker and the scheduler alive under supervision loops', function () {
    $startupScript = File::get(base_path('startup.sh'));

    expect($startupScript)
        ->toContain('while true; do php artisan queue:work --sleep=3 --tries=1 --timeout=420 --max-time=3600; sleep 5; done &')
        ->toContain('while true; do php artisan schedule:work; sleep 5; done &');
});

it('never starts the queue worker or the scheduler without a restart loop', function () {
    $lines = preg_split('/\R/', File::get(base_path('startup.sh')));

    $unsupervised = array_values(array_filter(
        $lines,
        fn (string $line): bool => preg_match('/^\s*php artisan (queue:work|schedule:(work|run))/', $line) === 1,
    ));

    expect($unsupervised)->toBe([]);
});

it('ships a .user.ini in public/ to configure PHP upload limits for Azure', function () {
    $userIniPath = public_path('.user.ini');

    expect(File::exists($userIniPath))->toBeTrue();

    $userIni = File::get($userIniPath);

    expect($userIni)->toContain('upload_max_filesize')
        ->and($userIni)->toContain('post_max_size')
        ->and($userIni)->toContain('memory_limit');
});
