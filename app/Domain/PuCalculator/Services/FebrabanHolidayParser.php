<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\FebrabanHolidayObservation;
use App\Domain\PuCalculator\Enums\CalendarSourceObservationKind;
use App\Domain\PuCalculator\Exceptions\FebrabanHolidayImportException;
use App\Domain\PuCalculator\Support\EasterMovableFeasts;
use Carbon\CarbonImmutable;
use JsonException;

/**
 * Interpreta as duas listas JSON publicadas pela FEBRABAN para um ano.
 *
 * Formato observado em ambos os endpoints (`/Home/ObterFeriadosFederais` e `.../ObterFeriadosFederaisF`):
 * um array de objetos `{"diaMes":"04 de junho","diaSemana":"quinta-feira","nomeFeriado":"Corpus Christi"}`.
 * O ANO não vem no payload — ele é o parâmetro da requisição —, o que torna o `diaSemana` a única
 * conferência disponível contra uma leitura deslocada de ano. O parser exige essa coerência.
 *
 * Duas defesas existem porque a fonte nunca devolve erro HTTP:
 *
 * 1. `diaSemana` vazio indica ano não interpretado pela fonte (ela ecoa a lista fixa sem dia da semana);
 * 2. ausência das datas móveis indica ano fora da curadoria publicada — a fonte devolve só os feriados
 *    de data fixa, e a carga pareceria válida enquanto omite Carnaval, Paixão e Corpus Christi.
 */
final class FebrabanHolidayParser
{
    private const MIN_YEAR = 1990;

    private const MAX_YEAR = 2200;

    /** @var array<string, int> */
    private const MONTHS = [
        'janeiro' => 1, 'fevereiro' => 2, 'marco' => 3, 'abril' => 4,
        'maio' => 5, 'junho' => 6, 'julho' => 7, 'agosto' => 8,
        'setembro' => 9, 'outubro' => 10, 'novembro' => 11, 'dezembro' => 12,
    ];

    /** @var array<string, int> dia da semana pt-BR => ISO-8601 (1 = segunda) */
    private const WEEKDAYS = [
        'segunda-feira' => 1, 'terca-feira' => 2, 'quarta-feira' => 3, 'quinta-feira' => 4,
        'sexta-feira' => 5, 'sabado' => 6, 'domingo' => 7,
    ];

    /**
     * @return array{0: list<FebrabanHolidayObservation>, 1: list<string>} observações, erros de linha
     */
    public function parse(
        string $payload,
        int $year,
        CalendarSourceObservationKind $kind,
    ): array {
        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new FebrabanHolidayImportException(sprintf(
                'A resposta da FEBRABAN para %d não é um JSON válido: %s.',
                $year,
                $exception->getMessage(),
            ), previous: $exception);
        }

        if (is_array($decoded) && array_key_exists('mensagemErro', $decoded)) {
            throw new FebrabanHolidayImportException(sprintf(
                'A FEBRABAN recusou a consulta de %d: %s',
                $year,
                (string) $decoded['mensagemErro'],
            ));
        }

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw new FebrabanHolidayImportException(sprintf(
                'A resposta da FEBRABAN para %d não é a lista de feriados esperada.',
                $year,
            ));
        }

        $observations = [];
        $errors = [];
        $seen = [];

        foreach ($decoded as $index => $row) {
            if (! is_array($row)) {
                $errors[] = sprintf('Item %d ignorado: estrutura inesperada.', $index + 1);

                continue;
            }

            $dayMonth = trim((string) ($row['diaMes'] ?? ''));
            $weekday = trim((string) ($row['diaSemana'] ?? ''));
            $name = trim((string) ($row['nomeFeriado'] ?? ''));

            if ($weekday === '') {
                $errors[] = sprintf(
                    'Item %d ("%s") ignorado: a FEBRABAN devolveu o registro sem dia da semana, o que indica que %d não foi interpretado como ano válido pela fonte.',
                    $index + 1,
                    $dayMonth,
                    $year,
                );

                continue;
            }

            $date = $this->resolveDate($dayMonth, $year);

            if (! $date instanceof CarbonImmutable) {
                $errors[] = sprintf('Item %d ignorado: data ilegível ("%s").', $index + 1, $dayMonth);

                continue;
            }

            if (! $this->weekdayMatches($date, $weekday)) {
                $errors[] = sprintf(
                    'Item %d ignorado: a fonte informou "%s" para %s, que na verdade é %s.',
                    $index + 1,
                    $weekday,
                    $date->toDateString(),
                    $this->weekdayLabel($date),
                );

                continue;
            }

            $key = $date->toDateString();

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $observations[] = new FebrabanHolidayObservation(
                date: $date,
                name: $name !== '' ? $name : 'Feriado bancário FEBRABAN',
                kind: $kind,
                weekdayLabel: $weekday,
            );
        }

        usort(
            $observations,
            static fn (FebrabanHolidayObservation $a, FebrabanHolidayObservation $b): int => $a->dateKey() <=> $b->dateKey(),
        );

        return [$observations, $errors];
    }

    /**
     * Confirma que a fonte publicou de fato o ano pedido. Sem isto, um ano fora da curadoria entra como
     * carga válida e incompleta — o pior resultado possível, porque as datas móveis ausentes passariam
     * a ser tratadas como dias úteis.
     *
     * @param  list<FebrabanHolidayObservation>  $observations  observações de mercado do ano
     */
    public function assertYearIsPublished(int $year, array $observations): void
    {
        $present = [];

        foreach ($observations as $observation) {
            $present[$observation->dateKey()] = true;
        }

        $missing = [];

        foreach (EasterMovableFeasts::financialMarketFeasts($year) as $dateKey => $label) {
            if (! isset($present[$dateKey])) {
                $missing[] = sprintf('%s (%s)', $label, $dateKey);
            }
        }

        if ($missing === []) {
            return;
        }

        throw new FebrabanHolidayImportException(sprintf(
            'A FEBRABAN respondeu para %d sem as datas móveis %s. O endpoint devolve HTTP 200 para qualquer ano, '
            .'ecoando apenas os feriados de data fixa quando o ano não está na curadoria publicada; importar esta '
            .'resposta marcaria essas datas como dias ÚTEIS. Verifique se %d consta no seletor de anos da fonte.',
            $year,
            implode(', ', $missing),
            $year,
        ));
    }

    public function assertYearInRange(int $year): void
    {
        if ($year < self::MIN_YEAR || $year > self::MAX_YEAR) {
            throw new FebrabanHolidayImportException(sprintf(
                'Ano %d fora da faixa suportada (%d–%d).',
                $year,
                self::MIN_YEAR,
                self::MAX_YEAR,
            ));
        }
    }

    private function resolveDate(string $dayMonth, int $year): ?CarbonImmutable
    {
        if (preg_match('/^(\d{1,2})\s+de\s+(.+)$/iu', $dayMonth, $matches) !== 1) {
            return null;
        }

        $day = (int) $matches[1];
        $month = self::MONTHS[$this->normalize($matches[2])] ?? null;

        if ($month === null || ! checkdate($month, $day, $year)) {
            return null;
        }

        return CarbonImmutable::create($year, $month, $day, 0, 0, 0);
    }

    private function weekdayMatches(CarbonImmutable $date, string $weekday): bool
    {
        $expected = self::WEEKDAYS[$this->normalize($weekday)] ?? null;

        return $expected !== null && $expected === (int) $date->format('N');
    }

    private function weekdayLabel(CarbonImmutable $date): string
    {
        $isoDay = (int) $date->format('N');

        return (string) array_search($isoDay, self::WEEKDAYS, true);
    }

    private function normalize(string $value): string
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);

        return strtolower(trim($ascii !== false ? $ascii : $value));
    }
}
