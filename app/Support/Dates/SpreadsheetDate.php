<?php

declare(strict_types=1);

namespace App\Support\Dates;

use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Leitura estrita de uma data vinda de célula de planilha -- a mesma para os
 * importadores de contratos, parcelas, unidades e valores de unidade.
 *
 * Aceita apenas o que não deixa dúvida:
 *
 * - a célula de data do próprio Excel, que o leitor entrega como objeto;
 * - texto `dd/mm/aaaa`, com hora opcional (`dd/mm/aaaa hh:mm[:ss]`);
 * - texto ISO `aaaa-mm-dd`, com hora opcional.
 *
 * O ano tem sempre quatro dígitos. `DateTime::createFromFormat('d/m/Y')` aceita
 * de um a quatro, sem erro nem aviso, e `05/03/26` virava o ano 26 d.C.; e
 * `Carbon::parse()` lê barra no formato americano, e `03/09/26` virava 9 de
 * março. Nenhum dos dois é usado aqui. Número cru (a data serial do Excel sem
 * formatação de data) também é recusado: `46000.5` virava 1970-01-01.
 *
 * Datas anteriores a {@see self::MINIMUM_YEAR} são recusadas como implausíveis:
 * nenhuma venda, recebimento ou vigência da carteira é tão antiga, e um ano
 * assim quase sempre é dígito perdido na digitação (`05/03/0202`).
 */
final class SpreadsheetDate
{
    public const MINIMUM_YEAR = 1990;

    /**
     * Complemento das mensagens de data inválida, igual em todos os importadores.
     */
    public const FORMAT_HINT = 'Utilize o formato dd/mm/aaaa, com o ano completo (a partir de 1990).';

    private const BRAZILIAN_PATTERN = '#^(\d{1,2})/(\d{1,2})/(\d{4})(?:\s+(\d{1,2}):(\d{2})(?::(\d{2}))?)?$#';

    private const ISO_PATTERN = '#^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T](\d{1,2}):(\d{2})(?::(\d{2}))?)?$#';

    /**
     * A data civil (`Y-m-d`) da célula, ou `null` quando ela não está num dos
     * formatos aceitos ou cai fora da faixa plausível.
     */
    public static function parse(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            $date = CarbonImmutable::instance($value);

            return self::build((int) $date->year, (int) $date->month, (int) $date->day);
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if (preg_match(self::BRAZILIAN_PATTERN, $value, $matches) === 1) {
            [, $day, $month, $year] = $matches;

            return self::hasValidTime($matches) ? self::build((int) $year, (int) $month, (int) $day) : null;
        }

        if (preg_match(self::ISO_PATTERN, $value, $matches) === 1) {
            [, $year, $month, $day] = $matches;

            return self::hasValidTime($matches) ? self::build((int) $year, (int) $month, (int) $day) : null;
        }

        return null;
    }

    /**
     * Se a data ainda não chegou no calendário de negócio (America/Sao_Paulo).
     *
     * Serve aos fatos -- venda, recebimento --, que só se registram depois de
     * acontecer. Vencimento e vigência podem, com razão, estar no futuro.
     */
    public static function isAfterBusinessToday(string $date): bool
    {
        return $date > BusinessTime::dateString();
    }

    /**
     * A data interpretada no formato da conferência (`dd/mm/aaaa`), ou travessão.
     */
    public static function display(?string $date): string
    {
        return blank($date) ? '—' : CarbonImmutable::parse($date)->format('d/m/Y');
    }

    private static function build(int $year, int $month, int $day): ?string
    {
        if (($year < self::MINIMUM_YEAR) || ! checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /**
     * A hora é opcional e descartada, mas uma hora impossível (`25:00`) denuncia
     * uma célula que não é a data que parece ser.
     *
     * @param  array<int, string>  $matches
     */
    private static function hasValidTime(array $matches): bool
    {
        if (! isset($matches[4])) {
            return true;
        }

        $hour = (int) $matches[4];
        $minute = (int) $matches[5];
        $second = (int) ($matches[6] ?? 0);

        return ($hour <= 23) && ($minute <= 59) && ($second <= 59);
    }
}
