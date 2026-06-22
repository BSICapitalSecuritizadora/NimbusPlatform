<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Support;

class PuVersionNumber
{
    /**
     * Extrai o ordinal de uma string de versao, reconhecendo padroes alem de "vN".
     *
     * Exemplos: "v1" => 1, "V 2" => 2, "3" => 3, "v01" => 1, "versao-4" => 4,
     * "rev_10" => 10, "" / "final" => null.
     */
    public static function ordinal(?string $version): ?int
    {
        if ($version === null) {
            return null;
        }

        if (preg_match('/(\d+)\s*$/', trim($version), $matches) === 1) {
            return (int) $matches[1];
        }

        if (preg_match('/(\d+)/', $version, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Maior ordinal reconhecivel em uma lista de versoes (0 se nenhuma for reconhecivel).
     *
     * @param  iterable<string|null>  $versions
     */
    public static function highestOrdinal(iterable $versions): int
    {
        $highest = 0;

        foreach ($versions as $version) {
            $ordinal = self::ordinal($version);

            if ($ordinal !== null && $ordinal > $highest) {
                $highest = $ordinal;
            }
        }

        return $highest;
    }

    /**
     * Proxima versao no formato canonico "vN" a partir de uma lista existente.
     *
     * @param  iterable<string|null>  $versions
     */
    public static function formatNext(iterable $versions): string
    {
        return 'v'.(self::highestOrdinal($versions) + 1);
    }

    public static function isRecognizable(?string $version): bool
    {
        return self::ordinal($version) !== null;
    }
}
