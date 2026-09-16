<?php

use App\Domain\PuCalculator\Enums\CalendarSourceObservationKind;
use App\Domain\PuCalculator\Exceptions\FebrabanHolidayImportException;
use App\Domain\PuCalculator\Services\FebrabanHolidayImporter;
use App\Domain\PuCalculator\Services\FebrabanHolidayParser;
use App\Domain\PuCalculator\Support\BusinessCalendarRegistry;
use App\Domain\PuCalculator\Support\EasterMovableFeasts;
use App\Models\BusinessHoliday;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\Calendars\FebrabanSourceFixture;

uses(RefreshDatabase::class);

it('calcula as datas móveis de 2026 conforme publicadas pela fonte', function (): void {
    expect(EasterMovableFeasts::carnivalMonday(2026)->toDateString())->toBe('2026-02-16')
        ->and(EasterMovableFeasts::carnivalTuesday(2026)->toDateString())->toBe('2026-02-17')
        ->and(EasterMovableFeasts::ashWednesday(2026)->toDateString())->toBe('2026-02-18')
        ->and(EasterMovableFeasts::goodFriday(2026)->toDateString())->toBe('2026-04-03')
        ->and(EasterMovableFeasts::corpusChristi(2026)->toDateString())->toBe('2026-06-04');

    // A quarta-feira de cinzas não é feriado de mercado e por isso não integra a guarda de completude.
    expect(array_keys(EasterMovableFeasts::financialMarketFeasts(2026)))
        ->toEqualCanonicalizing(['2026-02-16', '2026-02-17', '2026-04-03', '2026-06-04']);
});

it('classifica cada tabela da fonte com a semântica correta', function (): void {
    $parser = new FebrabanHolidayParser;

    [$market] = $parser->parse(
        json_encode([['diaMes' => '04 de junho', 'diaSemana' => 'quinta-feira', 'nomeFeriado' => 'Corpus Christi']]),
        2026,
        CalendarSourceObservationKind::FinancialNonBusinessDay,
    );
    [$special] = $parser->parse(
        json_encode([['diaMes' => '31 de dezembro', 'diaSemana' => 'quinta-feira', 'nomeFeriado' => 'Último dia útil do ano']]),
        2026,
        CalendarSourceObservationKind::SpecialBankingHours,
    );

    expect($market[0]->dateKey())->toBe('2026-06-04')
        ->and($market[0]->affectsBusinessDayDecision())->toBeTrue()
        ->and($special[0]->dateKey())->toBe('2026-12-31')
        ->and($special[0]->affectsBusinessDayDecision())->toBeFalse();
});

it('descarta registro cujo dia da semana não corresponde à data', function (): void {
    [$observations, $errors] = (new FebrabanHolidayParser)->parse(
        json_encode([['diaMes' => '04 de junho', 'diaSemana' => 'segunda-feira', 'nomeFeriado' => 'Corpus Christi']]),
        2026,
        CalendarSourceObservationKind::FinancialNonBusinessDay,
    );

    expect($observations)->toBe([])
        ->and($errors[0])->toContain('quinta-feira');
});

it('rejeita resposta sem dia da semana, sinal de ano não interpretado pela fonte', function (): void {
    [$observations, $errors] = (new FebrabanHolidayParser)->parse(
        json_encode([['diaMes' => '01 de janeiro', 'diaSemana' => '', 'nomeFeriado' => 'Confraternização Universal']]),
        2026,
        CalendarSourceObservationKind::FinancialNonBusinessDay,
    );

    expect($observations)->toBe([])
        ->and($errors[0])->toContain('sem dia da semana');
});

it('propaga a mensagem de erro devolvida pela própria fonte', function (): void {
    expect(fn () => (new FebrabanHolidayParser)->parse(
        json_encode(['mensagemErro' => 'Ano inválido']),
        2026,
        CalendarSourceObservationKind::FinancialNonBusinessDay,
    ))->toThrow(FebrabanHolidayImportException::class, 'Ano inválido');
});

it('nunca consulta a base de feriados municipais', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        '*/Home/ObterFeriadosFederaisF*' => Http::response('[]', 200),
        '*/Home/ObterFeriadosFederais*' => Http::response(FebrabanSourceFixture::payload([
            ['2026-01-01', 'Confraternização Universal'],
            ['2026-02-16', 'Carnaval'],
            ['2026-02-17', 'Carnaval'],
            ['2026-04-03', 'Sexta-Feira da Paixão'],
            ['2026-06-04', 'Corpus Christi'],
        ]), 200),
    ]);

    app(FebrabanHolidayImporter::class)->importFromSource([2026]);

    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'Municipais'));

    // Nenhuma evidência de âmbito local entra no calendário nacional.
    expect(BusinessHoliday::query()
        ->where('calendar_code', BusinessCalendarRegistry::BR_FINANCIAL_MARKET)
        ->whereDate('holiday_date', '2026-01-25') // aniversário da cidade de São Paulo
        ->exists())->toBeFalse();
});

it('falha alto quando a fonte responde erro HTTP, sem fallback silencioso', function (): void {
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response('', 503)]);

    expect(fn () => app(FebrabanHolidayImporter::class)->importFromSource([2026]))
        ->toThrow(FebrabanHolidayImportException::class, 'HTTP 503');

    expect(BusinessHoliday::query()->count())->toBe(0);
});

it('importa de arquivo local com o envelope das duas tabelas', function (): void {
    Http::preventStrayRequests();

    $path = temporaryTestFilePath('febraban-2026', 'json');
    file_put_contents($path, json_encode([
        'market' => json_decode(FebrabanSourceFixture::payload([
            ['2026-01-01', 'Confraternização Universal'],
            ['2026-02-16', 'Carnaval'],
            ['2026-02-17', 'Carnaval'],
            ['2026-04-03', 'Sexta-Feira da Paixão'],
            ['2026-06-04', 'Corpus Christi'],
        ]), true),
        'special_hours' => json_decode(FebrabanSourceFixture::payload([
            ['2026-02-18', 'Quarta-Feira de Cinzas'],
        ]), true),
    ], JSON_THROW_ON_ERROR));

    $result = app(FebrabanHolidayImporter::class)->importFromFile($path, 2026);

    expect($result->marketDays)->toBe(5)
        ->and($result->specialHoursDays)->toBe(1)
        ->and($result->calendarApplied)->toBe(0)
        ->and(BusinessHoliday::query()
            ->where('source', FebrabanHolidayImporter::SOURCE_MARKET)
            ->count())->toBe(5);
});

it('registra a base normativa junto da evidência', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        '*/Home/ObterFeriadosFederaisF*' => Http::response('[]', 200),
        '*/Home/ObterFeriadosFederais*' => Http::response(FebrabanSourceFixture::payload([
            ['2026-01-01', 'Confraternização Universal'],
            ['2026-02-16', 'Carnaval'],
            ['2026-02-17', 'Carnaval'],
            ['2026-04-03', 'Sexta-Feira da Paixão'],
            ['2026-06-04', 'Corpus Christi'],
        ]), 200),
    ]);

    app(FebrabanHolidayImporter::class)->importFromSource([2026]);

    $holiday = BusinessHoliday::query()->whereDate('holiday_date', '2026-06-04')->first();

    expect($holiday->source_revision)->toBe(FebrabanHolidayImporter::NORM_REFERENCE)
        ->and($holiday->source_revision)->toContain('4.880')
        ->and($holiday->source_is_official)->toBeTrue()
        ->and($holiday->checksum)->not->toBeNull()
        ->and($holiday->import_run_id)->not->toBeNull();
});
