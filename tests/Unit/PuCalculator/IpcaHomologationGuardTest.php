<?php

use App\Domain\PuCalculator\Calculators\IpcaCurveCalculator;
use App\Domain\PuCalculator\Enums\PuCalculationMethod;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Exceptions\IndexerNotSupportedException;
use App\Models\Emission;

it('keeps CDI and Prefixado homologated while IPCA stays experimental', function () {
    expect(PuIndexer::Cdi->isHomologated())->toBeTrue()
        ->and(PuIndexer::Prefixed->isHomologated())->toBeTrue()
        ->and(PuIndexer::Ipca->isHomologated())->toBeFalse();
});

it('labels IPCA as in preparation', function () {
    expect(PuIndexer::Ipca->label())->toContain('preparação');
});

it('blocks the IPCA calculator with a clear in-preparation message', function () {
    $calculator = new IpcaCurveCalculator;

    expect(fn () => $calculator->calculate(new Emission))
        ->toThrow(IndexerNotSupportedException::class, 'A curva IPCA ainda está em preparação');
});

it('keeps the IPCA calculation method flagged as experimental', function () {
    expect(PuCalculationMethod::forIndexer(PuIndexer::Ipca))->toBe(PuCalculationMethod::IpcaCorrected)
        ->and(PuCalculationMethod::IpcaCorrected->engineVersion())->toContain('experimental')
        ->and(PuCalculationMethod::IpcaCorrected->label())->toContain('experimental');
});

it('does not homologate IPCA just because a validation gabarito exists', function () {
    $gabarito = glob(base_path('docs/samples/pu-validation/*RIO BRANCO*.xlsx'));

    expect($gabarito)->not->toBeEmpty()
        ->and(PuIndexer::Ipca->isHomologated())->toBeFalse();
});
