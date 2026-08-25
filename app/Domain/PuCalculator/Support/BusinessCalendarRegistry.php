<?php

namespace App\Domain\PuCalculator\Support;

use InvalidArgumentException;

final class BusinessCalendarRegistry
{
    /**
     * Alias legado preservado para compatibilidade. Ele continua consultando os próprios dados `B3`
     * e NÃO é redirecionado silenciosamente. A migração dos consumidores exige revisão contratual.
     */
    public const LEGACY_B3 = 'B3';

    public const BR_BANKING_ANBIMA = 'BR_BANKING_ANBIMA';

    public const B3_LISTED_TRADING = 'B3_LISTED_TRADING';

    public const BR_NATIONAL_HOLIDAYS = 'BR_NATIONAL_HOLIDAYS';

    /**
     * @return array<string, array{label:string, meaning:string, legacy:bool, legacy_alias_of:?string, accepts_anbima:bool}>
     */
    public static function definitions(): array
    {
        return [
            self::LEGACY_B3 => [
                'label' => 'B3 (legado — semântica ANBIMA atual)',
                'meaning' => 'Código legado mantido sem remapeamento para preservar cálculos existentes. Deve ser eliminado após revisão individual dos consumidores.',
                'legacy' => true,
                'legacy_alias_of' => self::BR_BANKING_ANBIMA,
                'accepts_anbima' => true,
            ],
            self::BR_BANKING_ANBIMA => [
                'label' => 'ANBIMA — calendário bancário',
                'meaning' => 'Calendário bancário baseado na publicação de feriados bancários da ANBIMA.',
                'legacy' => false,
                'legacy_alias_of' => null,
                'accepts_anbima' => true,
            ],
            self::B3_LISTED_TRADING => [
                'label' => 'B3 — sessões de negociação',
                'meaning' => 'Calendário de sessões do mercado listado da B3. Não recebe importações ANBIMA.',
                'legacy' => false,
                'legacy_alias_of' => null,
                'accepts_anbima' => false,
            ],
            self::BR_NATIONAL_HOLIDAYS => [
                'label' => 'Feriados Nacionais — Brasil',
                'meaning' => 'Sábados, domingos e feriados de âmbito nacional instituídos por legislação federal. Não inclui automaticamente feriados bancários, Carnaval, Corpus Christi, feriados locais ou sessões B3.',
                'legacy' => false,
                'legacy_alias_of' => null,
                'accepts_anbima' => false,
            ],
        ];
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::definitions())
            ->mapWithKeys(static fn (array $definition, string $code): array => [$code => $definition['label']])
            ->all();
    }

    public static function normalize(string $calendarCode): string
    {
        return strtoupper(trim($calendarCode));
    }

    public static function ensureKnown(string $calendarCode): string
    {
        $normalized = self::normalize($calendarCode);

        if (! array_key_exists($normalized, self::definitions())) {
            throw new InvalidArgumentException(sprintf(
                'Calendário [%s] não suportado. Use %s.',
                $calendarCode,
                implode(', ', array_keys(self::definitions())),
            ));
        }

        return $normalized;
    }

    public static function acceptsAnbima(string $calendarCode): bool
    {
        $normalized = self::ensureKnown($calendarCode);

        return self::definitions()[$normalized]['accepts_anbima'];
    }

    public static function label(string $calendarCode): string
    {
        $normalized = self::normalize($calendarCode);

        return self::definitions()[$normalized]['label'] ?? $normalized;
    }

    public static function meaning(string $calendarCode): string
    {
        $normalized = self::normalize($calendarCode);

        return self::definitions()[$normalized]['meaning'] ?? 'Calendário não catalogado.';
    }
}
