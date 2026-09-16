<?php

use App\Domain\PuCalculator\Services\BusinessCalendarCatalogService;
use App\Domain\PuCalculator\Services\BusinessCalendarSelectionEvidenceService;
use App\Domain\PuCalculator\Support\BusinessCalendarRegistry;
use App\Models\BusinessCalendar;
use App\Models\BusinessCalendarSelectionEvidence;
use App\Models\Emission;
use App\Models\EmissionPuParameter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('catalogues official, legacy and pending calendars with explicit metadata', function () {
    $catalog = app(BusinessCalendarCatalogService::class);
    $definitions = $catalog->definitions();

    expect(BusinessCalendar::query()->count())->toBe(5)
        // A quinta entrada é o calendário financeiro consolidado, registrado deliberadamente. Fixar o
        // conjunto exato de códigos impede tanto que ele desapareça quanto que outro entre sem revisão.
        ->and(array_keys($definitions))->toEqualCanonicalizing([
            BusinessCalendarRegistry::LEGACY_B3,
            BusinessCalendarRegistry::BR_BANKING_ANBIMA,
            BusinessCalendarRegistry::B3_LISTED_TRADING,
            BusinessCalendarRegistry::BR_FINANCIAL_MARKET,
            BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS,
        ])
        ->and($definitions[BusinessCalendarRegistry::LEGACY_B3])->toMatchArray([
            'legacy' => true,
            'available_for_new_configurations' => false,
            'source' => 'ANBIMA (carga histórica legada)',
        ])
        ->and($definitions[BusinessCalendarRegistry::BR_BANKING_ANBIMA])->toMatchArray([
            'official' => true,
            'type' => 'banking',
            'accepts_anbima' => true,
            'available_for_new_configurations' => true,
        ])
        ->and($definitions[BusinessCalendarRegistry::B3_LISTED_TRADING])->toMatchArray([
            'official' => false,
            'type' => 'listed_trading',
            'accepts_anbima' => false,
            'status' => 'awaiting_official_source',
            'financial_use_allowed' => false,
            'available_for_new_configurations' => false,
        ])
        ->and($definitions[BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS])->toMatchArray([
            'official' => true,
            'type' => 'federal_legal_holidays',
            'materialization_policy' => 'weekday_with_official_exceptions',
            'financial_use_allowed' => false,
            'available_for_new_configurations' => false,
        ])
        ->and($definitions[BusinessCalendarRegistry::BR_FINANCIAL_MARKET])->toMatchArray([
            'label' => 'BR_FINANCIAL_MARKET — Mercado financeiro brasileiro — calendário consolidado',
            'official' => true,
            'type' => 'financial_market',
            'source' => 'Reconciliação ANBIMA × FEBRABAN — Resolução CMN 4.880, de 23.12.2020',
            'status' => 'review_required',
            'import_mode' => 'source_reconciliation',
            // explicit_official_decisions é o que faz uma data sem cobertura levantar exceção em vez de
            // ser inferida como dia útil de segunda a sexta.
            'materialization_policy' => 'explicit_official_decisions',
            'coverage_basis' => 'explicit_dates',
            'accepts_anbima' => false,
            'legacy' => false,
            'homologation' => false,
            // Nasce sem consumidores: fora das configurações novas até a homologação.
            'financial_use_allowed' => false,
            'available_for_new_configurations' => false,
            'confirmed_years_count' => 0,
        ]);
});

it('hides HML, legacy and calendars without an approved source from new configuration options', function () {
    BusinessCalendar::factory()->create([
        'code' => 'HML_PU_REFERENCE',
        'name' => 'Homologação PU',
        'is_homologation' => true,
        'available_for_new_configurations' => true,
    ]);

    $catalog = app(BusinessCalendarCatalogService::class);
    $newOptions = $catalog->optionsForNewConfiguration();
    $legacyOptions = $catalog->optionsForNewConfiguration(BusinessCalendarRegistry::LEGACY_B3);

    expect($newOptions)->toHaveKey(BusinessCalendarRegistry::BR_BANKING_ANBIMA)
        ->not->toHaveKey(BusinessCalendarRegistry::LEGACY_B3)
        ->not->toHaveKey(BusinessCalendarRegistry::B3_LISTED_TRADING)
        ->not->toHaveKey(BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS)
        ->not->toHaveKey(BusinessCalendarRegistry::BR_FINANCIAL_MARKET)
        ->not->toHaveKey('HML_PU_REFERENCE')
        ->and($legacyOptions)->toHaveKey(BusinessCalendarRegistry::LEGACY_B3);
});

it('records optional contractual evidence and confirmation without changing the selected consumer', function () {
    $user = User::factory()->create();
    $emission = Emission::factory()->create();
    $parameter = $emission->puParameter()->create([
        'curve_start_date' => '2026-01-01',
        'curve_end_date' => '2026-12-31',
        'initial_unit_value' => '1000.0000000000000000',
        'spread_rate' => '6.50000000',
        'indexer' => 'CDI',
        'business_day_basis' => 252,
        'calendar_code' => BusinessCalendarRegistry::BR_BANKING_ANBIMA,
        'index_rate_lookup_mode' => 'previous_available_business_day',
        'legacy_projection_enabled' => true,
    ]);

    $evidence = app(BusinessCalendarSelectionEvidenceService::class)->record(
        $parameter,
        'pu_business_day_calendar',
        BusinessCalendarRegistry::BR_BANKING_ANBIMA,
        [
            'source_document' => 'Termo de Securitização.pdf',
            'clause_reference' => 'Cláusula 5.1',
            'page_reference' => '42',
            'excerpt' => 'Dia útil conforme calendário bancário.',
        ],
        $user->id,
        confirmed: true,
    );

    expect($evidence)->toBeInstanceOf(BusinessCalendarSelectionEvidence::class)
        ->and($evidence->subject)->toBeInstanceOf(EmissionPuParameter::class)
        ->and($evidence->confirmed_by)->toBe($user->id)
        ->and($evidence->confirmed_at)->not->toBeNull()
        ->and($parameter->fresh()->calendar_code)->toBe(BusinessCalendarRegistry::BR_BANKING_ANBIMA);
});
