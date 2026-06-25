<?php

use App\Domain\PuCalculator\Contracts\IndexRateProvider;
use App\Domain\PuCalculator\DTOs\IndexRateData;
use App\Domain\PuCalculator\Enums\IpcaProjectionPolicy;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Exceptions\IndexerNotSupportedException;
use App\Domain\PuCalculator\Services\IpcaIndexResolver;
use App\Domain\PuCalculator\Services\IpcaProjectionPolicyService;
use Carbon\CarbonImmutable;

/**
 * Provider em memória para isolar o resolver da camada de persistência.
 *
 * @param  array<string, IndexRateData>  $timeline
 */
function fakeIndexProvider(array $timeline): IndexRateProvider
{
    return new class($timeline) implements IndexRateProvider
    {
        /** @param array<string, IndexRateData> $timeline */
        public function __construct(private array $timeline) {}

        public function rateForDate(PuIndexer $indexer, CarbonImmutable $date): ?IndexRateData
        {
            return $this->timeline[$date->toDateString()] ?? null;
        }

        public function exactRateForDate(PuIndexer $indexer, CarbonImmutable $date): ?IndexRateData
        {
            return $this->timeline[$date->toDateString()] ?? null;
        }
    };
}

function makeResolver(IndexRateProvider $provider): IpcaIndexResolver
{
    return new IpcaIndexResolver($provider, new IpcaProjectionPolicyService);
}

it('resolves a published index regardless of the projection policy', function () {
    $provider = fakeIndexProvider([
        '2024-08-01' => new IndexRateData(CarbonImmutable::parse('2024-08-01'), '6966.50', false, source: 'reference_workbook'),
    ]);

    $resolution = makeResolver($provider)->resolve(
        CarbonImmutable::parse('2024-08-01'),
        IpcaProjectionPolicy::PublishedOnly->value,
        CarbonImmutable::parse('2024-09-17'),
    );

    expect($resolution->isProjected)->toBeFalse()
        ->and($resolution->type())->toBe('published')
        ->and($resolution->value)->toBe('6966.50')
        ->and($resolution->source)->toBe('reference_workbook')
        ->and($resolution->policy)->toBe(IpcaProjectionPolicy::PublishedOnly);
});

it('resolves a projected index when the market policy allows it', function () {
    $provider = fakeIndexProvider([
        '2026-01-01' => new IndexRateData(
            CarbonImmutable::parse('2026-01-01'),
            '7300.00',
            true,
            source: 'market_curve',
            projectionSource: 'ANBIMA',
            projectionReferenceDate: CarbonImmutable::parse('2025-12-15'),
        ),
    ]);

    $resolution = makeResolver($provider)->resolve(
        CarbonImmutable::parse('2026-01-01'),
        IpcaProjectionPolicy::Market->value,
        CarbonImmutable::parse('2026-01-23'),
    );

    expect($resolution->isProjected)->toBeTrue()
        ->and($resolution->type())->toBe('projected')
        ->and($resolution->projectionSource)->toBe('ANBIMA')
        ->and($resolution->projectionReferenceDate?->toDateString())->toBe('2025-12-15')
        ->and($resolution->policy)->toBe(IpcaProjectionPolicy::Market);
});

it('refuses a projected index when the policy does not allow projection', function () {
    $provider = fakeIndexProvider([
        '2026-01-01' => new IndexRateData(CarbonImmutable::parse('2026-01-01'), '7300.00', true, source: 'market_curve'),
    ]);

    expect(fn () => makeResolver($provider)->resolve(
        CarbonImmutable::parse('2026-01-01'),
        IpcaProjectionPolicy::PublishedOnly->value,
        CarbonImmutable::parse('2026-01-23'),
    ))->toThrow(IndexerNotSupportedException::class, 'PROJETADO');
});

it('throws a clear exception naming the missing month and the required policy', function () {
    $resolver = makeResolver(fakeIndexProvider([]));

    expect(fn () => $resolver->resolve(
        CarbonImmutable::parse('2026-01-01'),
        IpcaProjectionPolicy::PublishedOnly->value,
        CarbonImmutable::parse('2026-01-23'),
    ))->toThrow(IndexerNotSupportedException::class, '2026-01-01');

    expect(fn () => $resolver->resolve(
        CarbonImmutable::parse('2026-01-01'),
        IpcaProjectionPolicy::Market->value,
        CarbonImmutable::parse('2026-01-23'),
    ))->toThrow(IndexerNotSupportedException::class, 'projeção de mercado');
});

it('defaults to published-only when the configured policy is null or unknown', function () {
    $service = new IpcaProjectionPolicyService;

    expect($service->resolvePolicy(null))->toBe(IpcaProjectionPolicy::PublishedOnly)
        ->and($service->resolvePolicy('garbage'))->toBe(IpcaProjectionPolicy::PublishedOnly)
        ->and($service->resolvePolicy('market'))->toBe(IpcaProjectionPolicy::Market)
        ->and($service->allowsProjection('market'))->toBeTrue()
        ->and($service->allowsProjection(null))->toBeFalse();
});
