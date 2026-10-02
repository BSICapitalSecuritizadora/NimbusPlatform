<?php

declare(strict_types=1);

namespace Tests\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Arr;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

use function Livewire\trigger;

/**
 * Uma chamada Livewire feita como um navegador a faria.
 *
 * `Livewire::test()` desliga os middlewares das requisições seguintes à
 * primeira (`SubsequentRender`) e só exercita o que o componente já tem em
 * memória. A recusa que importa provar é a do caminho real: a página aberta
 * pelos middlewares do painel, o `wire:snapshot` tirado do próprio HTML e o
 * POST na rota de update do Livewire com o cabeçalho `X-Livewire` -- exatamente
 * o que alguém faria pelo console do navegador para chamar uma ação que a tela
 * não mostra.
 */
final class ForgedLivewireRequest
{
    /**
     * O snapshot do componente `$componentClass` como o navegador o reenviaria.
     */
    public static function snapshotOf(string $html, string $componentClass): string
    {
        $found = [];

        foreach (self::snapshotNodes($html) as $node) {
            $snapshot = $node->getAttribute('wire:snapshot');
            $name = (string) data_get(json_decode($snapshot, true), 'memo.name');
            $found[] = $name;

            if ($name === $componentClass) {
                return $snapshot;
            }
        }

        throw new RuntimeException(sprintf(
            'O componente %s não está no HTML. Componentes encontrados: %s.',
            $componentClass,
            $found === [] ? 'nenhum' : implode(', ', $found),
        ));
    }

    /**
     * O snapshot do placeholder de um componente lazy e o argumento que o
     * navegador mandaria no `__lazyLoad`.
     *
     * @return array{0: string, 1: string}
     */
    public static function lazySnapshotOf(string $html, string $componentClass): array
    {
        $found = [];

        foreach (self::snapshotNodes($html) as $node) {
            $snapshot = $node->getAttribute('wire:snapshot');
            $name = (string) data_get(json_decode($snapshot, true), 'memo.name');
            $found[] = $name;

            $trigger = $node->getAttribute('x-intersect').$node->getAttribute('x-init');

            if (($name === $componentClass) && preg_match("/__lazyLoad\\('([^']+)'\\)/", $trigger, $matches)) {
                return [$snapshot, $matches[1]];
            }
        }

        throw new RuntimeException(sprintf(
            'O placeholder de %s não está no HTML. Componentes encontrados: %s.',
            $componentClass,
            $found === [] ? 'nenhum' : implode(', ', $found),
        ));
    }

    /**
     * POST na rota real de update do Livewire, com os middlewares ligados.
     *
     * O estado estático do Livewire é limpo antes, como aconteceria entre duas
     * requisições do navegador: sem isso, a pilha de componentes da requisição
     * anterior deste mesmo processo vazaria para esta.
     *
     * @param  list<array{path: string, method: string, params: array<mixed>, metadata: array<string, mixed>}>  $calls
     * @param  array<string, mixed>  $updates
     */
    public static function post(TestCase $test, string $snapshot, array $calls, array $updates = []): TestResponse
    {
        trigger('flush-state');

        return $test->postJson(route('default-livewire.update'), [
            '_token' => csrf_token(),
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => $updates,
                'calls' => $calls,
            ]],
        ], ['X-Livewire' => 'true']);
    }

    /**
     * Uma chamada no formato do payload do navegador.
     *
     * @param  array<mixed>  $params
     * @return array{path: string, method: string, params: array<mixed>, metadata: array<string, mixed>}
     */
    public static function call(string $method, array $params = [], bool $renderless = false): array
    {
        return [
            'path' => '',
            'method' => $method,
            'params' => $params,
            'metadata' => $renderless ? ['renderless' => true] : [],
        ];
    }

    /**
     * O snapshot que a resposta devolveu, para encadear a próxima chamada.
     */
    public static function nextSnapshot(TestResponse $response): string
    {
        return (string) $response->json('components.0.snapshot');
    }

    /**
     * As ações que ficaram montadas depois da chamada, pelo nome.
     *
     * O snapshot guarda arrays no formato em tupla do Livewire (`[valor,
     * {"s": "arr"}]`); a leitura desfaz a tupla antes de olhar os nomes.
     *
     * @return list<string>
     */
    public static function mountedActionNames(TestResponse $response): array
    {
        $snapshot = json_decode((string) $response->json('components.0.snapshot'), true);
        $mounted = self::untuple(data_get($snapshot, 'data.mountedActions')) ?? [];

        return array_values(array_map(
            fn (mixed $action): string => (string) data_get($action, 'name'),
            is_array($mounted) ? $mounted : [],
        ));
    }

    /**
     * Todo texto que a resposta trouxe -- HTML renderizado, retornos e
     * snapshot --, para provar que um dado não vazou por nenhum deles.
     */
    public static function renderedText(TestResponse $response): string
    {
        return collect(Arr::dot((array) $response->json('components.0')))
            ->filter(fn (mixed $value): bool => is_string($value))
            ->implode(PHP_EOL);
    }

    /**
     * @return list<DOMElement>
     */
    private static function snapshotNodes(string $html): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $nodes = (new DOMXPath($document))->query("//*[@*[name()='wire:snapshot']]");

        return array_values(array_filter(
            iterator_to_array($nodes === false ? [] : $nodes),
            fn (mixed $node): bool => $node instanceof DOMElement,
        ));
    }

    private static function untuple(mixed $value): mixed
    {
        if (is_array($value) && array_is_list($value) && (count($value) === 2) && is_array($value[1]) && array_key_exists('s', $value[1])) {
            $value = $value[0];
        }

        return is_array($value)
            ? array_map(fn (mixed $item): mixed => self::untuple($item), $value)
            : $value;
    }
}
