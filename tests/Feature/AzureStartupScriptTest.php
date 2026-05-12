<?php

use Illuminate\Support\Facades\File;

it('configures the Azure startup script to raise the nginx body size limit', function () {
    $startupScriptPath = base_path('startup.sh');

    expect(File::exists($startupScriptPath))->toBeTrue();

    $startupScript = File::get($startupScriptPath);

    expect($startupScript)->toContain('NGINX_CLIENT_MAX_BODY_SIZE')
        ->and($startupScript)->toContain('client_max_body_size')
        ->and($startupScript)->toContain('/etc/nginx/sites-available/default')
        ->and($startupScript)->toContain('service nginx reload || service nginx restart || true');
});
