<?php

declare(strict_types=1);

namespace App\Support\Imports;

use App\Providers\AppServiceProvider;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;
use WeakReference;

/**
 * A conferência que o operador viu, guardada entre as requisições do assistente.
 *
 * Cada passo do assistente é uma requisição nova. Sem isto, o envio, o Próximo e
 * o Confirmar reliam e reclassificavam a planilha inteira, e uma carteira mensal
 * passava do limite de tempo da requisição. Aqui o resumo calculado no envio é
 * guardado no cache padrão (Redis em produção) e as requisições seguintes só o
 * leem.
 *
 * A chave leva o tipo, o usuário, o escopo, o nome do envio temporário e o
 * SHA-256 do conteúdo: um envio novo -- mesmo do mesmo arquivo -- gera chave
 * nova, e uma conferência velha nunca é servida no lugar dela.
 *
 * Só arrays de escalares entram no cache: o resumo funciona com
 * `cache.serializable_classes` desligado.
 *
 * Bound como scoped no {@see AppServiceProvider}, e o memo ainda se refaz
 * quando a requisição corrente muda -- o que importa nos testes, em que as
 * requisições do Livewire compartilham o container. O memo registra de onde
 * veio cada resumo, que é o que o Confirmar usa para distinguir a conferência
 * que o operador viu da que acabou de ser recalculada.
 */
final class ImportConferenceStore
{
    public const TTL_MINUTES = 120;

    public const SCHEMA_VERSION = 1;

    public const KEY_PREFIX = 'importacoes:conferencia:v'.self::SCHEMA_VERSION.':';

    /**
     * O resumo veio do cache: foi calculado numa requisição anterior, e é o que
     * o operador está vendo.
     */
    private const ORIGIN_CACHE = 'cache';

    /**
     * O resumo foi calculado nesta requisição.
     */
    private const ORIGIN_COMPUTED = 'calculado';

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $memo = [];

    /**
     * @var array<string, string>
     */
    private array $origins = [];

    /**
     * Chaves para as quais o cache falhou nesta requisição, na leitura ou na
     * gravação. Para elas não há guarda: a conferência não pode ser lida de
     * volta.
     *
     * @var array<string, true>
     */
    private array $failedLookups = [];

    private bool $lastLookupFailed = false;

    /**
     * A requisição a que o memo pertence.
     *
     * @var WeakReference<object>|null
     */
    private ?WeakReference $request = null;

    public static function key(string $type, ?int $userId, ?int $scope, ImportSpreadsheetSource $source): string
    {
        return self::KEY_PREFIX.implode(':', [
            $type,
            $userId ?? 'anonimo',
            $scope ?? 'carteira',
            $source->temporaryFilename(),
            $source->checksum(),
        ]);
    }

    /**
     * O resumo guardado, ou `null` quando não há -- expirou, foi descartado ou o
     * cache está fora. Neste último caso {@see self::lastLookupFailed()} diz que
     * a ausência não é resposta, e o aviso técnico fica no log.
     *
     * @return array<string, mixed>|null
     */
    public function get(string $key): ?array
    {
        $this->followCurrentRequest();

        $this->lastLookupFailed = false;

        if (array_key_exists($key, $this->memo)) {
            $this->lastLookupFailed = isset($this->failedLookups[$key]);

            return $this->memo[$key];
        }

        $failed = false;

        $value = rescue(
            fn (): mixed => Cache::get($key),
            function (Throwable $exception) use (&$failed, $key): null {
                $failed = true;

                Log::warning('import-conference-cache-unavailable', [
                    'operation' => 'get',
                    'key' => $key,
                    'exception' => $exception::class,
                ]);

                return null;
            },
            report: false,
        );

        if ($failed) {
            $this->failedLookups[$key] = true;
            $this->lastLookupFailed = true;

            return null;
        }

        if (! is_array($value)) {
            return null;
        }

        $this->origins[$key] = self::ORIGIN_CACHE;

        return $this->memo[$key] = $value;
    }

    /**
     * Guarda o resumo para as requisições seguintes.
     *
     * A gravação que falha é o mesmo cache indisponível da leitura: a chave fica
     * marcada ({@see self::isUnavailable()}) e o Confirmar segue sem a guarda.
     * Antes só a leitura contava. Com um cache que lê mas recusa gravar -- o
     * Redis em failover (READONLY) ou cheio sem despejo --, toda requisição
     * recalculava, nada ficava guardado, e o Confirmar mostrava "Conferência
     * refeita." para sempre. O resumo anterior da chave, se houver, é
     * descartado: um resumo velho servido no lugar do que não pôde ser gravado
     * faria o Confirmar recusar a posição atual sem fim.
     *
     * @param  array<string, mixed>  $summary
     */
    public function put(string $key, array $summary): void
    {
        $this->followCurrentRequest();

        $this->memo[$key] = $summary;
        $this->origins[$key] = self::ORIGIN_COMPUTED;

        $failed = false;

        rescue(
            fn (): mixed => Cache::put($key, $summary, now()->addMinutes(self::TTL_MINUTES)),
            function (Throwable $exception) use (&$failed, $key): null {
                $failed = true;

                Log::warning('import-conference-cache-unavailable', [
                    'operation' => 'put',
                    'key' => $key,
                    'exception' => $exception::class,
                ]);

                return null;
            },
            report: false,
        );

        if ($failed) {
            $this->failedLookups[$key] = true;

            rescue(fn (): mixed => Cache::forget($key), null, report: false);
        }
    }

    public function forget(string $key): void
    {
        $this->followCurrentRequest();

        unset($this->memo[$key], $this->origins[$key], $this->failedLookups[$key]);

        rescue(fn (): mixed => Cache::forget($key), null, report: false);
    }

    /**
     * O resumo guardado ou, na falta dele, o calculado agora -- que passa a ser o
     * guardado.
     *
     * @param  Closure(): array<string, mixed>  $compute
     * @return array<string, mixed>
     */
    public function remember(string $key, Closure $compute): array
    {
        $summary = $this->get($key);

        if ($summary !== null) {
            return $summary;
        }

        $failed = $this->lastLookupFailed;

        $summary = $compute();

        $this->put($key, $summary);

        if ($failed) {
            $this->failedLookups[$key] = true;
        }

        return $summary;
    }

    /**
     * Se a última leitura não pôde consultar o cache.
     */
    public function lastLookupFailed(): bool
    {
        return $this->lastLookupFailed;
    }

    /**
     * Se o cache falhou para esta chave nesta requisição, na leitura ou na
     * gravação: a conferência não pode ser lida de volta numa requisição
     * seguinte, e o Confirmar segue sem a guarda -- como fazia antes de ela
     * existir --, com o aviso técnico já no log.
     */
    public function isUnavailable(string $key): bool
    {
        $this->followCurrentRequest();

        return isset($this->failedLookups[$key]);
    }

    /**
     * Se o resumo desta chave foi calculado nesta requisição, e não lido de uma
     * conferência anterior. É o caso da conferência que expirou: o operador não
     * viu o resumo novo, então ele não pode ser confirmado sem ser mostrado.
     */
    public function wasComputedInThisRequest(string $key): bool
    {
        $this->followCurrentRequest();

        return ($this->origins[$key] ?? null) === self::ORIGIN_COMPUTED;
    }

    /**
     * Esvazia o memo quando a requisição corrente não é aquela em que ele foi
     * preenchido. Uma requisição nunca herda o que outra calculou.
     */
    private function followCurrentRequest(): void
    {
        $current = app()->bound('request') ? app('request') : null;

        if (! is_object($current) || ($this->request?->get() === $current)) {
            return;
        }

        $this->memo = [];
        $this->origins = [];
        $this->failedLookups = [];
        $this->request = WeakReference::create($current);
    }
}
