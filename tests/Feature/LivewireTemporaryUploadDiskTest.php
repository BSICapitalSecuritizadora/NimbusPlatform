<?php

use Illuminate\Support\Facades\Process;

/**
 * O disco dos envios temporários do Livewire, como o `config/livewire.php` o
 * resolve -- avaliado num processo próprio, sem `.env` e só com as variáveis que
 * o caso define. Dentro da suíte o Livewire força o disco `tmp-for-tests`, e o
 * `.env` copiado pela esteira poderia definir a variável: nenhum dos dois
 * provaria o padrão.
 *
 * A produção tem FILESYSTEM_DISK=azure e nenhuma LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK.
 * Sem padrão no config, o Livewire caía no Azure: as conferências das importações
 * não liam a planilha, e planilhas com CPF/CNPJ iam para o container público.
 *
 * @param  array<string, string|false>  $environment  `false` remove a variável do processo
 */
function livewireTemporaryDiskWith(array $environment): string
{
    $script = <<<'PHP'
        require 'vendor/autoload.php';
        $config = require 'config/livewire.php';
        echo json_encode($config['temporary_file_upload']['disk']);
        PHP;

    $result = Process::path(base_path())
        ->env(['LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK' => false, ...$environment])
        ->run([PHP_BINARY, '-r', $script]);

    expect($result->successful())->toBeTrue($result->errorOutput());

    return (string) json_decode($result->output(), true);
}

it('falls back to the local disk when the default disk is azure and the variable is missing', function () {
    expect(livewireTemporaryDiskWith(['FILESYSTEM_DISK' => 'azure']))->toBe('local');
});

it('treats an empty variable as missing and still falls back to the local disk', function () {
    expect(livewireTemporaryDiskWith([
        'FILESYSTEM_DISK' => 'azure',
        'LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK' => '',
    ]))->toBe('local');
});

it('respects a temporary disk set explicitly', function () {
    expect(livewireTemporaryDiskWith([
        'FILESYSTEM_DISK' => 'azure',
        'LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK' => 'temporarios',
    ]))->toBe('temporarios');
});
