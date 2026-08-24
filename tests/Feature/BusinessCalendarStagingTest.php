<?php

use App\Domain\PuCalculator\Services\BusinessCalendarStagingService;
use App\Domain\PuCalculator\Support\BusinessCalendarRegistry;
use App\Models\BusinessCalendarDate;
use App\Models\BusinessCalendarStagingBatch;
use App\Models\BusinessCalendarYear;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('stages and approves official B3 evidence without applying or confirming production dates', function () {
    $stager = User::factory()->create();
    $reviewer = User::factory()->create();

    $batch = app(BusinessCalendarStagingService::class)->stage(
        BusinessCalendarRegistry::B3_LISTED_TRADING,
        2026,
        [
            ['date' => '2026-01-02', 'is_business_day' => true, 'description' => 'Sessão regular'],
            ['date' => '2026-01-05', 'is_business_day' => false, 'description' => 'Sem negociação'],
        ],
        'b3_official_document',
        'Ofício B3 001/2026',
        'rev-1',
        $stager->id,
    );

    expect($batch->status)->toBe(BusinessCalendarStagingBatch::STATUS_PENDING_REVIEW)
        ->and($batch->records_staged)->toBe(2)
        ->and($batch->dates)->toHaveCount(2)
        ->and(BusinessCalendarDate::query()->count())->toBe(0)
        ->and(BusinessCalendarYear::query()->count())->toBe(0);

    $approved = app(BusinessCalendarStagingService::class)->approve($batch, $reviewer->id, 'Documento conferido manualmente.');

    expect($approved->status)->toBe(BusinessCalendarStagingBatch::STATUS_APPROVED)
        ->and($approved->reviewed_by)->toBe($reviewer->id)
        ->and(BusinessCalendarDate::query()->count())->toBe(0)
        ->and(BusinessCalendarYear::query()->count())->toBe(0);
});

it('refuses ANBIMA as a B3 listed trading staging source', function () {
    expect(fn () => app(BusinessCalendarStagingService::class)->stage(
        BusinessCalendarRegistry::B3_LISTED_TRADING,
        2026,
        [['date' => '2026-01-02', 'is_business_day' => true]],
        'anbima',
        'feriados_nacionais.xls',
        null,
        null,
    ))->toThrow(InvalidArgumentException::class, 'não pode receber dados ANBIMA');

    expect(BusinessCalendarStagingBatch::query()->count())->toBe(0)
        ->and(BusinessCalendarDate::query()->count())->toBe(0);
});
