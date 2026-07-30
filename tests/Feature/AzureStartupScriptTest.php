<?php

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
        ->and($startupScript)->toContain('php artisan migrate --force --no-interaction')
        ->and($startupScript)->toContain('php artisan optimize')
        ->and($startupScript)->toContain('service nginx reload || service nginx restart || true');
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
