<?php

use App\Domain\PuCalculator\Enums\CalendarSourceReconciliationStatus;
use App\Domain\PuCalculator\Exceptions\FebrabanHolidayImportException;
use App\Domain\PuCalculator\Services\BusinessCalendarSourceReconciliationService;
use App\Domain\PuCalculator\Services\FebrabanHolidayImporter;
use App\Domain\PuCalculator\Support\BusinessCalendarRegistry;
use App\Models\BusinessCalendarDate;
use App\Models\BusinessCalendarImportRun;
use App\Models\BusinessHoliday;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuParameter;
use App\Models\Obligation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Calendars\FebrabanSourceFixture;

uses(RefreshDatabase::class);

it('confirma Corpus Christi de 2026 quando ANBIMA e FEBRABAN concordam', function (): void {
    FebrabanSourceFixture::anbimaFact('2026-06-04', 'Corpus Christi');
    FebrabanSourceFixture::fakeYear(FebrabanSourceFixture::marketPayload2026());

    app(FebrabanHolidayImporter::class)->importFromSource([2026]);

    $row = app(BusinessCalendarSourceReconciliationService::class)
        ->explain(CarbonImmutable::parse('2026-06-04'));

    expect($row['status'])->toBe(CalendarSourceReconciliationStatus::Confirmed->value)
        ->and($row['consolidated'])->toBe('non_business')
        ->and($row['evidence'])->toBe(['ANBIMA', 'FEBRABAN'])
        ->and($row['anbima']['decision'])->toBe('non_business')
        ->and($row['febraban']['decision'])->toBe('non_business');
});

it('classifica como source_only_anbima a data que só a ANBIMA conhece', function (): void {
    FebrabanSourceFixture::anbimaFact('2026-06-04', 'Corpus Christi');
    FebrabanSourceFixture::anbimaFact('2026-11-20', 'Dia da Consciência Negra');
    FebrabanSourceFixture::fakeYear(FebrabanSourceFixture::marketPayload2026());

    app(FebrabanHolidayImporter::class)->importFromSource([2026]);

    $row = app(BusinessCalendarSourceReconciliationService::class)
        ->explain(CarbonImmutable::parse('2026-11-20'));

    expect($row['status'])->toBe(CalendarSourceReconciliationStatus::SourceOnlyAnbima->value)
        ->and($row['consolidated'])->toBeNull()
        ->and($row['evidence'])->toBe(['ANBIMA']);
});

it('classifica como source_only_febraban a data que só a FEBRABAN conhece', function (): void {
    FebrabanSourceFixture::fakeYear(FebrabanSourceFixture::marketPayload2026([['2026-07-09', 'Data publicada apenas pela FEBRABAN']]));

    app(FebrabanHolidayImporter::class)->importFromSource([2026]);

    $row = app(BusinessCalendarSourceReconciliationService::class)
        ->explain(CarbonImmutable::parse('2026-07-09'));

    expect($row['status'])->toBe(CalendarSourceReconciliationStatus::SourceOnlyFebraban->value)
        ->and($row['consolidated'])->toBeNull()
        ->and($row['evidence'])->toBe(['FEBRABAN']);
});

it('marca conflito, sem decidir, quando as fontes divergem', function (): void {
    // A ANBIMA continua afirmando a data; a carga seguinte da FEBRABAN deixa de trazê-la. Uma fonte
    // afirma e a outra se retrata: divergência real, que precisa aparecer em vez de ser desempatada.
    FebrabanSourceFixture::anbimaFact('2026-11-20', 'Dia da Consciência Negra');

    $importer = app(FebrabanHolidayImporter::class);
    FebrabanSourceFixture::fakeYear(
        FebrabanSourceFixture::marketPayload2026([['2026-11-20', 'Dia da Consciência Negra']]),
    );
    $importer->importFromSource([2026]);

    FebrabanSourceFixture::fakeYear(FebrabanSourceFixture::marketPayload2026());
    $importer->importFromSource([2026]);

    $row = app(BusinessCalendarSourceReconciliationService::class)
        ->explain(CarbonImmutable::parse('2026-11-20'));

    expect($row['status'])->toBe(CalendarSourceReconciliationStatus::Conflict->value)
        ->and($row['consolidated'])->toBeNull()
        ->and($row['requires_human_decision'])->toBeTrue()
        ->and($row['febraban']['decision'])->toBe('retracted')
        ->and($row['anbima']['decision'])->toBe('non_business')
        // Nenhuma fonte "venceu": a evidência retratada permanece registrada.
        ->and($row['evidence'])->toBe(['ANBIMA']);
});

it('não converte expediente especial de agência em dia não útil', function (): void {
    FebrabanSourceFixture::fakeYear(
        FebrabanSourceFixture::marketPayload2026(),
        FebrabanSourceFixture::payload([
            ['2026-02-18', 'Quarta-Feira de Cinzas'],
            ['2026-12-31', 'Último dia útil do ano (Não haverá expediente ao público)'],
        ]),
    );

    app(FebrabanHolidayImporter::class)->importFromSource([2026]);

    $specialHours = BusinessHoliday::query()
        ->where('calendar_code', BusinessCalendarRegistry::BR_FINANCIAL_MARKET)
        ->where('source', FebrabanHolidayImporter::SOURCE_SPECIAL_HOURS)
        ->pluck('holiday_date')
        ->map(fn ($date): string => CarbonImmutable::parse((string) $date)->toDateString())
        ->all();

    expect($specialHours)->toEqualCanonicalizing(['2026-02-18', '2026-12-31']);

    $reconciliation = app(BusinessCalendarSourceReconciliationService::class);

    foreach (['2026-02-18', '2026-12-31'] as $dateKey) {
        $row = $reconciliation->explain(CarbonImmutable::parse($dateKey));

        expect($row['consolidated'])->toBeNull()
            ->and($row['status'])->toBe(CalendarSourceReconciliationStatus::Unknown->value)
            ->and($row['febraban'])->toBeNull()
            ->and($row['special_hours'])->not->toBeNull();
    }

    // 24/12 não é publicado por nenhuma tabela: nem evidência, nem decisão.
    expect($reconciliation->explain(CarbonImmutable::parse('2026-12-24'))['status'])
        ->toBe(CalendarSourceReconciliationStatus::Unknown->value);
});

it('recusa um ano que a fonte não publicou, em vez de importar lista incompleta', function (): void {
    // O endpoint responde HTTP 200 para qualquer ano, ecoando só as datas fixas.
    FebrabanSourceFixture::fakeYear(FebrabanSourceFixture::payload([
        ['2030-01-01', 'Confraternização Universal'],
        ['2030-12-25', 'Natal'],
    ]));

    expect(fn () => app(FebrabanHolidayImporter::class)->importFromSource([2030]))
        ->toThrow(FebrabanHolidayImportException::class, 'datas móveis');

    expect(BusinessHoliday::query()->where('calendar_code', BusinessCalendarRegistry::BR_FINANCIAL_MARKET)->count())
        ->toBe(0);

    // A recusa fica auditada: a tentativa existe, marcada como falha.
    $run = BusinessCalendarImportRun::query()->where('year', 2030)->first();

    expect($run)->not->toBeNull()
        ->and($run->result)->toBe(BusinessCalendarImportRun::RESULT_FAILED);
});

it('é idempotente: reimportar a mesma fonte não duplica evidência', function (): void {
    FebrabanSourceFixture::fakeYear(FebrabanSourceFixture::marketPayload2026());
    $importer = app(FebrabanHolidayImporter::class);

    $first = $importer->importFromSource([2026]);
    $second = $importer->importFromSource([2026]);

    expect($first->imported)->toBe(6)
        ->and($second->imported)->toBe(0)
        ->and($second->skipped)->toBe(6)
        ->and(BusinessHoliday::query()->where('calendar_code', BusinessCalendarRegistry::BR_FINANCIAL_MARKET)->count())
        ->toBe(6);
});

it('detecta remoção sem apagar a evidência anterior', function (): void {
    FebrabanSourceFixture::fakeYear(FebrabanSourceFixture::marketPayload2026([['2026-07-09', 'Data que sairá da fonte']]));
    $importer = app(FebrabanHolidayImporter::class);
    $importer->importFromSource([2026]);

    FebrabanSourceFixture::fakeYear(FebrabanSourceFixture::marketPayload2026());
    $result = $importer->importFromSource([2026]);

    $removed = BusinessHoliday::query()
        ->where('calendar_code', BusinessCalendarRegistry::BR_FINANCIAL_MARKET)
        ->whereDate('holiday_date', '2026-07-09')
        ->first();

    expect($result->removalsDetected)->toBe(1)
        ->and($removed)->not->toBeNull()
        ->and($removed->removed_detected_at)->not->toBeNull();
});

it('não persiste nada em dry-run, mas registra a execução auditada', function (): void {
    FebrabanSourceFixture::fakeYear(FebrabanSourceFixture::marketPayload2026());

    $result = app(FebrabanHolidayImporter::class)->importFromSource([2026], dryRun: true);

    expect($result->dryRun)->toBeTrue()
        ->and($result->imported)->toBe(6)
        ->and($result->marketDays)->toBe(6)
        ->and(BusinessHoliday::query()->count())->toBe(0)
        ->and(BusinessCalendarImportRun::query()->where('dry_run', true)->count())->toBe(1);
});

it('reporta cobertura incompleta em vez de tratar ausência como confirmação', function (): void {
    FebrabanSourceFixture::anbimaFact('2026-06-04', 'Corpus Christi');
    FebrabanSourceFixture::fakeYear(FebrabanSourceFixture::marketPayload2026());
    app(FebrabanHolidayImporter::class)->importFromSource([2026]);

    $coverage = app(BusinessCalendarSourceReconciliationService::class)->coverage(
        CarbonImmutable::parse('2026-01-01'),
        CarbonImmutable::parse('2027-12-31'),
    );

    expect($coverage['coverage_status'])->toBe('incomplete')
        ->and($coverage['reconcilable_years'])->toBe([2026])
        ->and($coverage['years_without_full_coverage'])->toBe([2027]);

    $row = app(BusinessCalendarSourceReconciliationService::class)
        ->explain(CarbonImmutable::parse('2027-06-03'));

    expect($row['status'])->toBe(CalendarSourceReconciliationStatus::Unknown->value)
        ->and($row['consolidated'])->toBeNull();
});

it('não materializa calendário nem toca em dados operacionais', function (): void {
    FebrabanSourceFixture::anbimaFact('2026-06-04', 'Corpus Christi');
    $anbimaBefore = BusinessHoliday::query()->where('source', 'anbima')->get()->toArray();

    FebrabanSourceFixture::fakeYear(
        FebrabanSourceFixture::marketPayload2026(),
        FebrabanSourceFixture::payload([['2026-12-31', 'Último dia útil do ano (Não haverá expediente ao público)']]),
    );

    app(FebrabanHolidayImporter::class)->importFromSource([2026]);

    expect(BusinessCalendarDate::query()->count())->toBe(0)
        ->and(EmissionPuParameter::query()->count())->toBe(0)
        ->and(EmissionPuCurveVersion::query()->count())->toBe(0)
        ->and(Obligation::query()->count())->toBe(0)
        ->and(BusinessHoliday::query()->where('source', 'anbima')->get()->toArray())->toEqual($anbimaBefore);
});

it('recusa importação ANBIMA dirigida ao calendário consolidado', function (): void {
    expect(BusinessCalendarRegistry::acceptsAnbima(BusinessCalendarRegistry::BR_FINANCIAL_MARKET))
        ->toBeFalse();

    expect(BusinessCalendarRegistry::acceptsAnbima(BusinessCalendarRegistry::BR_BANKING_ANBIMA))
        ->toBeTrue();
});
