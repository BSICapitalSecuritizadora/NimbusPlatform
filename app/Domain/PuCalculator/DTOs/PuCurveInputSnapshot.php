<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

use App\Domain\PuCalculator\Exceptions\PuCurveInputsException;
use Carbon\CarbonImmutable;

/**
 * Retrato canônico dos insumos contratuais de uma curva de PU.
 *
 * `payload` é a parte SEMÂNTICA -- exatamente o que a engine usa, normalizado e em
 * ordem canônica -- e é a única que entra no fingerprint. `provenance` explica de
 * onde cada item veio (ids das linhas, contribuintes do horizonte, quando foi
 * capturado) e fica fora do hash: renumerar uma linha ou capturar de novo o mesmo
 * contrato não muda o fingerprint.
 *
 * O formato tem versão própria ({@see self::SCHEMA}), separada da versão numérica
 * da engine: um retrato `v1` nunca é lido como outro formato.
 */
final readonly class PuCurveInputSnapshot
{
    public const SCHEMA = 'pu-curve-inputs.v1';

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $provenance
     */
    public function __construct(
        public string $schema,
        public array $payload,
        public string $fingerprint,
        public array $provenance = [],
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $provenance
     */
    public static function make(array $payload, array $provenance = []): self
    {
        $canonical = self::canonicalize($payload);

        return new self(self::SCHEMA, $canonical, self::hash($canonical), $provenance);
    }

    /**
     * Relê o retrato gravado numa versão e PROVA que o conteúdo ainda corresponde
     * ao fingerprint gravado junto. Retrato adulterado ou de formato desconhecido
     * é recusado: a extensão nunca calcula sobre um retrato que não se explica.
     *
     * @param  array<string, mixed>  $stored
     */
    public static function fromStored(array $stored, ?string $expectedFingerprint = null): self
    {
        $schema = (string) ($stored['schema'] ?? '');
        $payload = $stored['payload'] ?? null;

        if ($schema !== self::SCHEMA || ! is_array($payload)) {
            throw new PuCurveInputsException(sprintf(
                'O retrato de insumos da versão está num formato desconhecido (%s).',
                $schema !== '' ? $schema : 'sem formato',
            ));
        }

        $canonical = self::canonicalize($payload);
        $fingerprint = self::hash($canonical);

        if (($stored['fingerprint'] ?? null) !== $fingerprint
            || ($expectedFingerprint !== null && ! hash_equals($expectedFingerprint, $fingerprint))) {
            throw new PuCurveInputsException('O retrato de insumos da versão não corresponde ao fingerprint gravado.');
        }

        return new self($schema, $canonical, $fingerprint, is_array($stored['provenance'] ?? null) ? $stored['provenance'] : []);
    }

    /**
     * @return array{schema: string, fingerprint: string, payload: array<string, mixed>, provenance: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'schema' => $this->schema,
            'fingerprint' => $this->fingerprint,
            'payload' => $this->payload,
            'provenance' => $this->provenance,
        ];
    }

    public function sameInputsAs(self $other): bool
    {
        return $this->schema === $other->schema && hash_equals($this->fingerprint, $other->fingerprint);
    }

    /** @return array<string, mixed> */
    public function engine(): array
    {
        return $this->payload['engine'] ?? [];
    }

    /** @return array<string, mixed> */
    public function terms(): array
    {
        return $this->payload['terms'] ?? [];
    }

    /** @return array<string, mixed> */
    public function horizon(): array
    {
        return $this->payload['horizon'] ?? [];
    }

    /** @return list<array<string, mixed>> */
    public function events(): array
    {
        return array_values($this->payload['events'] ?? []);
    }

    /** @return list<array{date: string, quantity: string}> */
    public function integralizations(): array
    {
        return array_values($this->payload['integralizations'] ?? []);
    }

    public function curveStartDate(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $this->horizon()['curve_start_date'])->startOfDay();
    }

    /**
     * Último dia que a curva calcula: o vencimento contratual ou, se um pagamento
     * do vencimento foi deslocado para depois dele, a data efetiva desse pagamento.
     */
    public function horizonEndDate(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $this->horizon()['curve_end_date'])->startOfDay();
    }

    public function contractualMaturityDate(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $this->horizon()['contractual_maturity_date'])->startOfDay();
    }

    /**
     * Identidade de um evento no retrato: tipo, data efetiva e sequência -- a mesma
     * da unique dos eventos ativos.
     *
     * @param  array<string, mixed>  $event
     */
    public static function eventIdentity(array $event): string
    {
        return sprintf('%s|%s|%d', (string) $event['event_type'], (string) $event['effective_date'], (int) ($event['sequence'] ?? 0));
    }

    /**
     * Ordena chaves de mapas recursivamente e preserva a ordem das listas: a ordem
     * semântica de uma lista é decidida por quem a monta, nunca pela serialização.
     */
    public static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => self::canonicalize($item), $value);
    }

    /**
     * @param  array<string, mixed>  $canonicalPayload
     */
    private static function hash(array $canonicalPayload): string
    {
        return hash('sha256', json_encode(
            ['schema' => self::SCHEMA, 'payload' => $canonicalPayload],
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }
}
