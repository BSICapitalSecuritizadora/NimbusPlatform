<?php

declare(strict_types=1);

namespace App\Support\SalesBoards;

use BackedEnum;
use Carbon\CarbonInterface;
use DateTimeInterface;
use HashContext;
use InvalidArgumentException;

/**
 * Resumo determinístico de um conjunto de fatos.
 *
 * Um fingerprint só serve para alguma coisa se a mesma realidade produzir sempre
 * o mesmo hash, em qualquer máquina, em qualquer banco e em qualquer ordem de
 * carregamento. Isso não sai de graça de `json_encode()` nem de `serialize()`:
 * o primeiro depende de flags e da ordem de inserção das chaves, o segundo, de
 * detalhes de implementação da classe. Aqui cada valor vira uma forma canônica
 * única antes de entrar no hash.
 *
 * As conversões são fechadas:
 *
 * - datas viram `Y-m-d` -- a hora nunca participa de uma decisão do Quadro;
 * - dinheiro entra em centavos inteiros e percentual em basis points, e a
 *   conversão é responsabilidade de quem chama, com {@see IntegerMoney};
 * - enums entram pelo `value`, nunca pelo nome do case;
 * - booleanos viram `0`/`1`;
 * - `null` tem marcador próprio, e não vira string vazia: "sem valor conhecido"
 *   e "texto vazio" são fatos diferentes e não podem colidir no hash.
 *
 * `float` é recusado. Um fingerprint calculado sobre ponto flutuante herda a
 * imprecisão dele: o mesmo valor gravado por dois caminhos diferentes produziria
 * hashes diferentes, e uma competência inteira ficaria obsoleta sem que nada
 * tivesse mudado.
 *
 * O hash é SHA-256 alimentado incrementalmente, então o custo de memória não
 * cresce com o tamanho do snapshot -- uma obra com centenas de unidades e
 * milhares de parcelas passa por aqui sem montar um documento gigante antes.
 *
 * Nada disso é calculado no banco. `GROUP_CONCAT` tem limite de tamanho
 * configurável que trunca em silêncio, `SHA2()` não existe no SQLite, e o Quadro
 * precisa dar o mesmo resultado nos dois motores.
 */
final class CanonicalDigest
{
    /**
     * Ausência explícita. Escolhido fora do alfabeto dos dados canônicos para
     * não poder ser confundido com um valor real.
     */
    public const NULL_MARKER = '~';

    private const FIELD_SEPARATOR = '|';

    private HashContext $context;

    public function __construct()
    {
        $this->context = hash_init('sha256');
    }

    /**
     * Abre uma seção nomeada.
     *
     * Sem isso, duas seções vizinhas com o mesmo formato de linha poderiam
     * trocar conteúdo entre si sem alterar o hash.
     */
    public function section(string $name): self
    {
        hash_update($this->context, '#'.$name."\n");

        return $this;
    }

    /**
     * Acrescenta uma linha já canonizada por {@see self::row()}.
     */
    public function append(string $canonicalRow): self
    {
        hash_update($this->context, $canonicalRow."\n");

        return $this;
    }

    /**
     * @param  list<string>  $canonicalRows
     */
    public function appendAll(array $canonicalRows): self
    {
        foreach ($canonicalRows as $row) {
            $this->append($row);
        }

        return $this;
    }

    public function digest(): string
    {
        return hash_final($this->context);
    }

    /**
     * Canoniza uma linha de fatos.
     *
     * @param  list<mixed>  $fields
     */
    public static function row(array $fields): string
    {
        return implode(self::FIELD_SEPARATOR, array_map(self::field(...), $fields));
    }

    /**
     * Hash de um conjunto de linhas já canonizadas, numa chamada.
     *
     * @param  array<string, list<string>>  $sections
     */
    public static function of(array $sections): string
    {
        $digest = new self;

        foreach ($sections as $name => $rows) {
            $digest->section($name)->appendAll($rows);
        }

        return $digest->digest();
    }

    /**
     * Forma canônica de um único valor.
     */
    public static function field(mixed $value): string
    {
        if ($value === null) {
            return self::NULL_MARKER;
        }

        if (is_float($value)) {
            throw new InvalidArgumentException(
                'Fingerprints não aceitam float: converta para centavos ou basis points inteiros antes.',
            );
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($value instanceof BackedEnum) {
            return self::escape((string) $value->value);
        }

        if ($value instanceof CarbonInterface) {
            return $value->toDateString();
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_int($value)) {
            return (string) $value;
        }

        return self::escape((string) $value);
    }

    /**
     * Protege o separador e a quebra de linha dentro de um texto livre.
     *
     * Um bloco chamado `A|1` não pode se passar por dois campos: sem escape,
     * `['A|1', 'x']` e `['A', '1|x']` produziriam a mesma linha e, portanto, o
     * mesmo hash para realidades diferentes.
     */
    private static function escape(string $value): string
    {
        return str_replace(['\\', self::FIELD_SEPARATOR, "\n", "\r"], ['\\\\', '\\|', '\\n', '\\r'], $value);
    }
}
