<?php

namespace Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    /**
     * Discos locais trocados por raízes descartáveis antes de cada teste.
     *
     * A suíte nunca deve alcançar `storage/app`: é lá que vivem os documentos
     * privados enviados pelos usuários. Um teste que grava ali deixa resíduo
     * entre execuções, cria diretórios 0700 (a visibilidade padrão do disco
     * privado) que o php-fpm do container não consegue ler, e -- no caso de um
     * teste que limpa o próprio cenário -- chega a apagar arquivos reais.
     *
     * `Storage::fake()` enraíza cada disco em `storage/framework/testing/disks`,
     * que é um diretório local de verdade: planilhas continuam sendo escritas e
     * lidas do disco, `->path()` continua devolvendo caminho físico e o checksum
     * continua saindo do arquivo real.
     *
     * O disco `private` fica de fora: é Azure Blob, não tem equivalente local, e
     * o único teste que o exercita monta o próprio fake -- que este TestCase
     * também isola e descarta, porque o isolamento vale para qualquer disco
     * falsificado durante o teste, não só para os desta lista.
     *
     * @var list<string>
     */
    private const ISOLATED_DISKS = ['local', 'public', 'resumes', self::TEMPORARY_UPLOAD_DISK];

    /**
     * Disco que o Livewire escolhe sozinho durante os testes
     * (`FileUploadConfiguration::disk()`), mas que não existe em
     * `config/filesystems.php` -- ele só faz sentido na suíte.
     */
    private const TEMPORARY_UPLOAD_DISK = 'tmp-for-tests';

    /**
     * Diretório que delimita o que o tearDown tem permissão de apagar, e sufixo
     * que identifica as raízes deste processo dentro dele. Guardados aqui
     * porque `storage_path()` já não responde depois que a aplicação é
     * destruída no tearDown.
     */
    private string $isolatedStorageSandbox = '';

    private string $isolatedStorageToken = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->isolateStorageFromRealFilesystem();
    }

    protected function tearDown(): void
    {
        $sandbox = $this->isolatedStorageSandbox;
        $token = $this->isolatedStorageToken;

        $this->isolatedStorageSandbox = '';
        $this->isolatedStorageToken = '';

        parent::tearDown();

        $this->discardIsolatedStorageRoots($sandbox, $token);
    }

    /**
     * Substitui os discos locais por raízes descartáveis, preservando o resto da
     * configuração de cada um (`url`, `visibility`, `throw`) para que o disco
     * falso se comporte como o verdadeiro. `config('filesystems')` fica
     * intocado de propósito: é ele que descreve o ambiente de produção, e há
     * teste que assere exatamente esses valores.
     */
    private function isolateStorageFromRealFilesystem(): void
    {
        // Sem isto, `Storage::fake()` usa `disks/<disco>` -- a mesma raiz para
        // todo processo fora do modo `--parallel` -- e limpa essa raiz a cada
        // teste. Duas execuções simultâneas de `php artisan test` no mesmo
        // checkout (duas janelas, um watcher, um agente) apagam os arquivos uma
        // da outra no meio do teste. Com o resolver abaixo cada processo ganha
        // o próprio sufixo, e sob `--parallel` o token do worker continua
        // valendo, que é o que o framework já usava.
        ParallelTesting::resolveTokenUsing(
            static fn (): string|int => $_SERVER['TEST_TOKEN'] ?? (getmypid() ?: 'single'),
        );

        $this->isolatedStorageSandbox = storage_path('framework/testing/disks');
        $this->isolatedStorageToken = (string) ParallelTesting::token();

        config()->set('filesystems.disks.'.self::TEMPORARY_UPLOAD_DISK, [
            'driver' => 'local',
            'root' => $this->isolatedStorageSandbox.'/'.self::TEMPORARY_UPLOAD_DISK,
            'throw' => false,
        ]);

        foreach (self::ISOLATED_DISKS as $disk) {
            Storage::fake($disk, Arr::except(
                (array) config('filesystems.disks.'.$disk, []),
                ['root'],
            ));
        }
    }

    /**
     * Remove as raízes deste processo para que nem `storage/framework` acumule
     * resíduo -- inclusive as de discos que o próprio teste falsificou, já que
     * todas carregam o mesmo sufixo.
     *
     * O par sandbox + sufixo não é decoração: é o que garante que a varredura
     * nunca alcance um diretório de outro processo, e muito menos um arquivo
     * real da aplicação.
     */
    private function discardIsolatedStorageRoots(string $sandbox, string $token): void
    {
        if ($sandbox === '' || $token === '') {
            return;
        }

        $filesystem = new Filesystem;

        foreach ((array) glob($sandbox.DIRECTORY_SEPARATOR.'*_test_'.$token, GLOB_ONLYDIR) as $root) {
            $filesystem->deleteDirectory((string) $root);
        }
    }
}
