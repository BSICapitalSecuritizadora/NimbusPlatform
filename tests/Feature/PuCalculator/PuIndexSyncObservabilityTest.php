<?php

use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexSyncOutcome;
use App\Domain\PuCalculator\Enums\PuOperationalConditionType;
use App\Domain\PuCalculator\Enums\PuOperationalFailureCategory;
use App\Domain\PuCalculator\Enums\PuOperationalSeverity;
use App\Domain\PuCalculator\Exceptions\BcbSgsException;
use App\Domain\PuCalculator\Services\IndexRateSyncService;
use App\Domain\PuCalculator\Services\PuOperationalMonitor;
use App\Models\IndexRate;
use App\Models\PuIndexSyncAttempt;
use App\Models\PuOperationalIncident;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Support\Pu\PuObligationFixture as Fx;

/**
 * Fase 6 -- cada consulta ao Banco Central fica registrada com o resultado
 * classificado. "A fonte respondeu sem observação nova" não é "a divulgação
 * chegou"; falha de rede, configuração recusada e resposta fora do formato não
 * são a mesma coisa; e nada disso enfraquece o registrador de observações nem a
 * correção governada da Fase 3.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'pu_indexes.bcb.chunk_months' => 12,
        'pu_indexes.bcb.retries' => 1,
        'pu_indexes.bcb.retry_sleep_ms' => 0,
    ]);
    Http::preventStrayRequests();
});

function p6sSync(string $from = '2026-03-02', string $to = '2026-03-06', bool $dryRun = false): void
{
    app(IndexRateSyncService::class)->sync(PuIndexer::Cdi, CarbonImmutable::parse($from), CarbonImmutable::parse($to), $dryRun);
}

function p6sPayload(array $dates, string $value = '14.90'): array
{
    return array_map(fn (string $date): array => ['data' => CarbonImmutable::parse($date)->format('d/m/Y'), 'valor' => $value], $dates);
}

it('records new observations, then a response without anything new, as different outcomes', function () {
    Http::fake(['api.bcb.gov.br/*' => Http::response(p6sPayload(['2026-03-02', '2026-03-03']))]);

    p6sSync();
    p6sSync();
    [$first, $second] = PuIndexSyncAttempt::query()->orderBy('id')->get()->all();

    expect($first->outcome)->toBe(PuIndexSyncOutcome::NewObservations)
        ->and($first->created)->toBe(2)
        ->and($first->latest_observation_date?->toDateString())->toBe('2026-03-03')
        ->and($first->finished_at)->not->toBeNull()
        ->and($second->outcome)->toBe(PuIndexSyncOutcome::NoNewObservation)
        ->and($second->created)->toBe(0)
        ->and($second->outcome->reachedProvider())->toBeTrue();
});

it('classifies provider failures by cause and keeps no secret in the stored message', function (Closure $response, PuOperationalFailureCategory $category) {
    Http::fake(['api.bcb.gov.br/*' => $response]);

    expect(fn () => p6sSync())->toThrow(BcbSgsException::class);

    $attempt = PuIndexSyncAttempt::query()->sole();

    expect($attempt->outcome)->toBe(PuIndexSyncOutcome::Failed)
        ->and($attempt->failure_category)->toBe($category)
        ->and($attempt->finished_at)->not->toBeNull()
        ->and($attempt->error_message)->not->toContain('s3cr3t')
        ->and(IndexRate::query()->count())->toBe(0);
})->with([
    'rede/timeout' => [fn () => fn () => throw new ConnectionException('cURL error 28: timeout https://user:s3cr3t@api.bcb.gov.br'), PuOperationalFailureCategory::ProviderUnavailable],
    'HTTP 503' => [fn () => Http::response('indisponível', 503), PuOperationalFailureCategory::ProviderUnavailable],
    'HTTP 401 (autenticação)' => [fn () => Http::response('negado', 401), PuOperationalFailureCategory::ProviderConfiguration],
    'HTTP 404 (série errada)' => [fn () => Http::response('não existe', 404), PuOperationalFailureCategory::ProviderConfiguration],
    'resposta não-JSON' => [fn () => Http::response('<html>manutenção</html>', 200), PuOperationalFailureCategory::MalformedResponse],
]);

it('counts malformed entries and conflicting provider values without overwriting what was recorded', function () {
    IndexRate::query()->create(['indexer' => 'CDI', 'rate_date' => '2026-03-02', 'rate_value' => '14.90000000', 'source' => 'bcb_sgs', 'source_reference' => 'bcb_sgs:4389', 'is_projected' => false]);
    Http::fake(['api.bcb.gov.br/*' => Http::response([
        ['data' => '02/03/2026', 'valor' => '15.10'],
        ['data' => '03/03/2026', 'valor' => '14.90'],
        ['data' => 'ontem', 'valor' => 'abc'],
    ])]);

    p6sSync();
    $attempt = PuIndexSyncAttempt::query()->sole();

    expect($attempt->outcome)->toBe(PuIndexSyncOutcome::RateConflict)
        ->and($attempt->conflicts)->toBe(1)
        ->and($attempt->invalid_entries)->toBe(1)
        ->and($attempt->created)->toBe(1)
        ->and((string) IndexRate::query()->whereDate('rate_date', '2026-03-02')->sole()->rate_value)->toBe('14.90000000');
});

it('does not record a dry run as an attempt', function () {
    Http::fake(['api.bcb.gov.br/*' => Http::response(p6sPayload(['2026-03-02']))]);

    p6sSync(dryRun: true);

    expect(PuIndexSyncAttempt::query()->count())->toBe(0);
});

it('escalates repeated synchronization failures to critical only when an official CDI curve depends on them', function () {
    config(['pu_calculator.monitoring.index_sync_failure_critical_after' => 3]);
    $sourceDown = true;
    Http::fake(['api.bcb.gov.br/*' => function () use (&$sourceDown) {
        return $sourceDown ? Http::response('indisponível', 503) : Http::response(p6sPayload(['2026-03-16']));
    }]);

    foreach ([1, 2, 3] as $attempt) {
        rescue(fn () => p6sSync(), report: false);
    }

    app(PuOperationalMonitor::class)->run('test');

    // Sem curva oficial (produção antes do lançamento): visível, mas sem incidente.
    expect(PuOperationalIncident::query()->count())->toBe(0);

    $emission = Fx::emission();
    Fx::official($emission);
    app(PuOperationalMonitor::class)->run('test');
    $incident = PuOperationalIncident::query()->open()->where('type', PuOperationalConditionType::IndexSyncFailed->value)->sole();

    expect($incident->severity)->toBe(PuOperationalSeverity::Critical)
        ->and($incident->indexer)->toBe('CDI')
        ->and($incident->context['consecutive_failures'])->toBe(3)
        ->and($incident->context['failure_category'])->toBe(PuOperationalFailureCategory::ProviderUnavailable->value)
        ->and($incident->context['retryable'])->toBeTrue();

    // A fonte volta: o incidente se resolve sozinho.
    $sourceDown = false;
    p6sSync('2026-03-16', '2026-03-16');
    app(PuOperationalMonitor::class)->run('test');

    expect($incident->fresh()->resolved_at)->not->toBeNull();
});
