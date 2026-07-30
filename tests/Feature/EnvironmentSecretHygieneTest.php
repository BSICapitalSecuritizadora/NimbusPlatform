<?php

use Symfony\Component\Finder\Finder;

/**
 * A-8: guarda-corpos de repositório. A rotação dos segredos e a migração para o
 * Key Vault acontecem fora do código; estes testes garantem que o repositório
 * continue livre de credenciais e que o `.env.example` documente cada variável
 * exigida pela camada de configuração — é a ausência dessa documentação que leva
 * alguém a colar valores de produção no `.env` local.
 */
it('keeps every secret placeholder empty in the example environment file (A-8)', function () {
    $offenders = [];

    foreach (exampleEnvironmentEntries() as $key => $value) {
        if (! isSecretEnvironmentKey($key)) {
            continue;
        }

        // Vazio, `null` literal e interpolação de outra variável são placeholders.
        if (in_array($value, ['', 'null'], true) || str_starts_with($value, '${')) {
            continue;
        }

        $offenders[] = $key;
    }

    expect($offenders)->toBe([]);
});

it('git-ignores every local environment file (A-8)', function () {
    $ignored = file(base_path('.gitignore'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    expect($ignored)->toContain('.env')
        ->toContain('.env.backup')
        ->toContain('.env.production')
        ->toContain('.env.staging')
        ->toContain('.env.testing');
});

it('documents every credential-bearing variable the config layer reads (A-8)', function () {
    $required = [
        'APP_KEY',
        'APP_PORTAL_URL',
        'AZURE_CLIENT_ID',
        'AZURE_CLIENT_SECRET',
        'AZURE_STORAGE_CONNECTION_STRING',
        'AZURE_STORAGE_CONTAINER',
        'AZURE_STORAGE_PRIVATE_CONTAINER',
        'AZURE_TENANT_ID',
        'CONTA_AZUL_CLIENT_ID',
        'CONTA_AZUL_CLIENT_SECRET',
        'CONTA_AZUL_REDIRECT_URI',
        'GEMINI_API_KEY',
        'OUTLOOK_CLIENT_ID',
        'OUTLOOK_CLIENT_SECRET',
        'OUTLOOK_MAILBOX',
        'OUTLOOK_TENANT_ID',
        'PRIVATE_FILESYSTEM_DISK',
    ];

    $documented = array_keys(exampleEnvironmentEntries());

    expect(array_values(array_diff($required, $documented)))->toBe([]);
});

it('does not ship credential-shaped strings in versioned files (A-8)', function () {
    $offenders = [];

    $finder = Finder::create()
        ->files()
        ->in([
            base_path('app'),
            base_path('bootstrap'),
            base_path('config'),
            base_path('database'),
            base_path('resources'),
            base_path('routes'),
            base_path('tests'),
        ])
        // Este arquivo carrega os próprios padrões de busca.
        ->notPath('Feature/EnvironmentSecretHygieneTest.php')
        ->name(['*.php', '*.js', '*.ts', '*.vue', '*.json', '*.yml', '*.yaml']);

    $files = array_merge(
        iterator_to_array($finder, false),
        [base_path('.env.example'), base_path('composer.json'), base_path('package.json')],
    );

    foreach ($files as $file) {
        $path = is_string($file) ? $file : $file->getRealPath();
        $contents = (string) file_get_contents($path);

        foreach (credentialPatterns() as $label => $pattern) {
            if (preg_match($pattern, $contents) === 1) {
                $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path).' → '.$label;
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('pins the test suite to a mailer that cannot deliver (A-8)', function () {
    expect(config('mail.default'))->toBe('array');
});

/**
 * Padrões de credencial com prefixo reconhecível. Não pretendem cobrir todo
 * segredo possível — cobrem os que vazam por descuido em arquivo versionado.
 *
 * @return array<string, string>
 */
function credentialPatterns(): array
{
    return [
        'google-api-key' => '/\bAIza[0-9A-Za-z_\-]{30,}/',
        'openai-key' => '/\bsk-[A-Za-z0-9]{32,}/',
        'aws-access-key-id' => '/\bAKIA[0-9A-Z]{16}\b/',
        'private-key-block' => '/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/',
        'azure-storage-account-key' => '/AccountKey=[A-Za-z0-9+\/]{60,}={0,2}/',
    ];
}

/**
 * @return array<string, string>
 */
function exampleEnvironmentEntries(): array
{
    $entries = [];

    foreach (file(base_path('.env.example'), FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        if (preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/', $line, $matches) !== 1) {
            continue;
        }

        $entries[$matches[1]] = trim($matches[2], '"\'');
    }

    return $entries;
}

function isSecretEnvironmentKey(string $key): bool
{
    return preg_match('/(SECRET|PASSWORD|API_KEY|CONNECTION_STRING|_TOKEN|_KEY)$/', $key) === 1;
}
