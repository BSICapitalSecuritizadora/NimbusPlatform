<?php

use Illuminate\Support\Facades\File;

/**
 * O clamd roda como sidecar do Azure App Service. Três detalhes da configuração
 * já causaram (ou quase causaram) uploads recusados em produção com o daemon no
 * ar, e nenhum deles falha de forma visível — o sintoma é sempre o mesmo
 * "antivírus indisponível". Estes testes travam os três.
 */
it('rejects ambiguous values for the antivirus flag', function (string $value) {
    withEnvironmentValue('CLAMAV_ENABLED', $value, function (): void {
        expect(fn (): array => require base_path('config/uploads.php'))
            ->toThrow(RuntimeException::class, 'não é um booleano válido');
    });
})->with(['enable', 'disable', 'enabled', 'disabled', 'sim', 'nao']);

it('accepts the values an operator is expected to type', function (string $value, bool $expected) {
    withEnvironmentValue('CLAMAV_ENABLED', $value, function () use ($expected): void {
        $config = require base_path('config/uploads.php');

        expect($config['clamav']['enabled'])->toBe($expected);
    });
})->with([
    ['true', true],
    ['false', false],
    ['1', true],
    ['0', false],
    ['on', true],
    ['off', false],
]);

it('falls back to enabling the antivirus in production when the flag is absent', function () {
    withEnvironmentValue('CLAMAV_ENABLED', null, function (): void {
        withEnvironmentValue('APP_ENV', 'production', function (): void {
            $config = require base_path('config/uploads.php');

            expect($config['clamav']['enabled'])->toBeTrue();
        });
    });
});

/**
 * O ClamAvFileScanner prefere o socket unix sempre que ele estiver definido: um
 * default não vazio faria o caminho TCP nunca ser tentado quando a variável
 * fosse removida do App Service.
 */
it('leaves the clamav socket unset so the tcp endpoint is used by default', function (?string $value) {
    withEnvironmentValue('CLAMAV_SOCKET', $value, function (): void {
        $config = require base_path('config/uploads.php');

        expect($config['clamav']['socket'])->toBeNull();
    });
})->with([null, '']);

it('still honours an explicitly configured unix socket', function () {
    withEnvironmentValue('CLAMAV_SOCKET', '/var/run/clamav/clamd.ctl', function (): void {
        $config = require base_path('config/uploads.php');

        expect($config['clamav']['socket'])->toBe('/var/run/clamav/clamd.ctl');
    });
});

/**
 * Sidecars do App Service não recebem DNS por nome: só `localhost` funciona.
 * Um hostname simbólico no template reproduz a falha no próximo ambiente.
 */
it('points the production template at a loopback address, never a hostname', function () {
    $template = File::get(base_path('.env.example.production'));

    preg_match('/^CLAMAV_HOST=(.*)$/m', $template, $matches);

    expect(trim($matches[1] ?? ''))->toBeIn(['127.0.0.1', 'localhost', '::1']);
});

it('keeps the clamav socket entry present but empty in the production template', function () {
    $template = File::get(base_path('.env.example.production'));

    expect($template)->toMatch('/^CLAMAV_SOCKET=\s*$/m');
});

it('only ships unambiguous booleans for the antivirus flag in the templates', function (string $file) {
    preg_match('/^CLAMAV_ENABLED=(.*)$/m', File::get(base_path($file)), $matches);

    expect(trim($matches[1] ?? ''))->toBeIn(['true', 'false']);
})->with(['.env.example', '.env.example.production']);

/**
 * Reavalia o arquivo de configuração com uma variável trocada e restaura o valor
 * anterior — inclusive a ausência dela.
 */
function withEnvironmentValue(string $key, ?string $value, Closure $assertions): void
{
    $hadValue = array_key_exists($key, $_ENV);
    $previous = $_ENV[$key] ?? null;

    if ($value === null) {
        unset($_ENV[$key], $_SERVER[$key]);
    } else {
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    try {
        $assertions();
    } finally {
        if ($hadValue) {
            $_ENV[$key] = $previous;
            $_SERVER[$key] = $previous;
        } else {
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }
}
