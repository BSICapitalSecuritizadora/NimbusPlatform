<?php

use App\Domain\PuCalculator\Contracts\B3DiSource;
use App\Domain\PuCalculator\DTOs\CdiSourceDataset;
use App\Domain\PuCalculator\Services\B3DiFileParser;
use App\Models\EmissionPuDailyCurve;
use App\Models\EmissionPuParameter;
use App\Models\IndexRate;
use App\Models\Obligation;
use App\Models\Payment;
use App\Models\PuHistory;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('creates an auditable dossier without financial side effects or automatic approval', function () {
    Storage::fake('local');
    config([
        'pu_indexes.source_homologation.artifact_disk' => 'local',
        'pu_indexes.source_homologation.minimum_common_records' => 2,
        'pu_indexes.bcb.chunk_months' => 12,
        'pu_indexes.bcb.retries' => 1,
        'pu_indexes.bcb.retry_sleep_ms' => 0,
    ]);

    $parser = app(B3DiFileParser::class);
    app()->bind(B3DiSource::class, fn (): B3DiSource => new class($parser) implements B3DiSource
    {
        public function __construct(private readonly B3DiFileParser $parser) {}

        public function fetch(CarbonImmutable $from, CarbonImmutable $to): CdiSourceDataset
        {
            $records = [
                $this->parser->parse('20250102.txt', '000001215'),
                $this->parser->parse('20250103.txt', '000001215'),
            ];

            return new CdiSourceDataset(
                source: 'b3_di',
                requestedFrom: $from,
                requestedTo: $to,
                capturedAt: CarbonImmutable::parse('2025-01-04 12:00:00'),
                records: $records,
                payloads: array_map(fn ($record): array => [
                    'source_reference' => $record->sourceReference,
                    'sha256' => $record->payloadSha256,
                    'content_base64' => base64_encode($record->rawValue),
                ], $records),
                metadata: ['published_scale' => 2],
            );
        }
    });

    Http::fake([
        'api.bcb.gov.br/*' => Http::response([
            ['data' => '02/01/2025', 'valor' => '12.15'],
            ['data' => '03/01/2025', 'valor' => '12,15'],
        ]),
    ]);
    Http::preventStrayRequests();

    $before = [
        'index_rates' => IndexRate::query()->count(),
        'pu_histories' => PuHistory::query()->count(),
        'payments' => Payment::query()->count(),
        'curves' => EmissionPuDailyCurve::query()->count(),
        'parameters' => EmissionPuParameter::query()->count(),
        'obligations' => Obligation::query()->count(),
    ];

    $this->artisan('pu:index-rates:homologate-di-source', [
        '--from' => '2025-01-01',
        '--to' => '2025-01-31',
    ])->assertSuccessful();

    $files = Storage::disk('local')->allFiles('homologations/index-rate-sources');
    expect($files)->toHaveCount(1);

    $report = json_decode((string) Storage::disk('local')->get($files[0]), true, flags: JSON_THROW_ON_ERROR);

    expect($report['classification'])->toBe('B — Equivalência comprovada com transformação')
        ->and($report['workflow_status'])->toBe('ready_for_review')
        ->and($report['approved'])->toBeFalse()
        ->and($report['comparison']['summary']['present_equal'])->toBe(2)
        ->and($report['comparison']['summary']['present_different'])->toBe(0)
        ->and($report['sources']['b3']['normalized_checksum'])->toBe($report['sources']['bcb']['normalized_checksum'])
        ->and($report['sources']['bcb']['payloads'][0]['body_base64'])->not->toBeEmpty()
        ->and($report['side_effect_policy']['index_rates'])->toBeFalse();

    expect([
        'index_rates' => IndexRate::query()->count(),
        'pu_histories' => PuHistory::query()->count(),
        'payments' => Payment::query()->count(),
        'curves' => EmissionPuDailyCurve::query()->count(),
        'parameters' => EmissionPuParameter::query()->count(),
        'obligations' => Obligation::query()->count(),
    ])->toBe($before);
});

it('can execute without writing an artifact', function () {
    config(['pu_indexes.source_homologation.minimum_common_records' => 1]);
    Storage::fake('local');
    $parser = app(B3DiFileParser::class);
    app()->bind(B3DiSource::class, fn (): B3DiSource => new class($parser) implements B3DiSource
    {
        public function __construct(private readonly B3DiFileParser $parser) {}

        public function fetch(CarbonImmutable $from, CarbonImmutable $to): CdiSourceDataset
        {
            return new CdiSourceDataset(
                source: 'b3_di',
                requestedFrom: $from,
                requestedTo: $to,
                capturedAt: CarbonImmutable::now(),
                records: [$this->parser->parse('20250102.txt', '000001215')],
                payloads: [],
            );
        }
    });
    Http::fake(['api.bcb.gov.br/*' => Http::response([
        ['data' => '02/01/2025', 'valor' => '12.15'],
    ])]);

    $this->artisan('pu:index-rates:homologate-di-source', [
        '--from' => '2025-01-01',
        '--to' => '2025-01-02',
        '--no-artifact' => true,
    ])->assertSuccessful();

    expect(Storage::disk('local')->allFiles())->toBe([]);
});
