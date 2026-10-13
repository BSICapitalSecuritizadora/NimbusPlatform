<?php

use App\Domain\PuCalculator\Calculators\IpcaCurveCalculator;
use App\Domain\PuCalculator\Enums\PuCalculationMethod;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexerCertification;

it('keeps only CDI operational while Prefixado and IPCA stay simulation-only until independently certified', function () {
    // Fase 7: o prefixado era "homologado" por declaração, sem referência
    // independente; perdeu o uso operacional até ser certificado.
    expect(PuIndexer::Cdi->isHomologated())->toBeTrue()
        ->and(PuIndexer::Cdi->certification())->toBe(PuIndexerCertification::ConditionallyCertified)
        ->and(PuIndexer::Prefixed->isHomologated())->toBeFalse()
        ->and(PuIndexer::Prefixed->certification())->toBe(PuIndexerCertification::NotCertified)
        ->and(PuIndexer::Ipca->isHomologated())->toBeFalse()
        ->and(PuIndexer::Ipca->certification())->toBe(PuIndexerCertification::NotCertified);
});

it('labels IPCA as in preparation', function () {
    expect(PuIndexer::Ipca->label())->toContain('preparação');
});

it('exposes an implemented IPCA calculator instead of a blocking skeleton', function () {
    $constructor = (new ReflectionClass(IpcaCurveCalculator::class))->getConstructor();

    expect($constructor)->not->toBeNull()
        ->and($constructor->getNumberOfParameters())->toBeGreaterThan(0);
});

it('keeps the IPCA calculation method flagged as experimental', function () {
    expect(PuCalculationMethod::forIndexer(PuIndexer::Ipca))->toBe(PuCalculationMethod::IpcaCorrected)
        ->and(PuCalculationMethod::IpcaCorrected->engineVersion())->toContain('experimental')
        ->and(PuCalculationMethod::IpcaCorrected->label())->toContain('experimental');
});

it('keeps static IPCA homologation gated independently of operational gabaritos', function () {
    expect(PuIndexer::Ipca->isHomologated())->toBeFalse();
});
