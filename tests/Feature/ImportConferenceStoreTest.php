<?php

use App\Support\Imports\ImportConferenceStore;
use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Facades\Cache;

/**
 * A conferência guardada entre as requisições do assistente quando o cache não
 * guarda: a gravação que falha conta como cache indisponível, do mesmo jeito
 * que a leitura que falha, e o Confirmar segue sem a guarda.
 */
it('marks the key unavailable when the cache refuses to write', function () {
    useCacheThatRefusesWrites();

    $store = new ImportConferenceStore;

    expect($store->get('importacoes:conferencia:teste'))->toBeNull()
        ->and($store->isUnavailable('importacoes:conferencia:teste'))->toBeFalse();

    $store->put('importacoes:conferencia:teste', ['digest' => 'abc']);

    expect($store->isUnavailable('importacoes:conferencia:teste'))->toBeTrue()
        // O resumo calculado continua valendo nesta requisição...
        ->and($store->get('importacoes:conferencia:teste'))->toBe(['digest' => 'abc'])
        // ...mas não pode ser lido de volta na próxima: não há guarda.
        ->and($store->lastLookupFailed())->toBeTrue();
});

/**
 * O resumo guardado antes de o cache passar a recusar gravações é o de uma
 * conferência anterior. Servido no lugar do que não pôde ser gravado, faria o
 * Confirmar recusar a posição atual a cada clique.
 */
it('drops the summary kept before the cache started refusing writes', function () {
    $full = new class extends ArrayStore
    {
        public function __construct()
        {
            parent::__construct();
            parent::put('importacoes:conferencia:teste', ['digest' => 'velho'], 600);
        }

        public function put($key, $value, $seconds)
        {
            throw new RuntimeException('OOM command not allowed when used memory > maxmemory.');
        }
    };

    Cache::extend('cheio-sem-despejo', fn () => Cache::repository($full));
    config(['cache.stores.cheio-sem-despejo' => ['driver' => 'cheio-sem-despejo'], 'cache.default' => 'cheio-sem-despejo']);

    $store = new ImportConferenceStore;

    expect($store->get('importacoes:conferencia:teste'))->toBe(['digest' => 'velho']);

    $store->put('importacoes:conferencia:teste', ['digest' => 'novo']);

    expect($store->isUnavailable('importacoes:conferencia:teste'))->toBeTrue()
        ->and(Cache::get('importacoes:conferencia:teste'))->toBeNull();
});
