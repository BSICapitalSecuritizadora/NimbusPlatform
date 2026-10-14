<?php

use App\Domain\PuCalculator\DTOs\PuOperationalReadinessReport;
use App\Domain\PuCalculator\Enums\PuEventType;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexerCertification;
use App\Domain\PuCalculator\Enums\PuOperationalReadinessStatus;
use App\Domain\PuCalculator\Services\PuOperationalPreflightService;
use App\Domain\PuCalculator\Services\PuOperationalReadinessService;
use App\Domain\PuCalculator\Support\BusinessCalendarRegistry;
use App\Domain\PuCalculator\Support\PuOperationsConfig;
use App\Models\BusinessCalendarDate;
use App\Models\Emission;
use App\Models\IndexRate;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\Pu\PuMarketCalendarFixture;
use Tests\Support\Pu\PuObligationFixture as Fx;
use Tests\Support\Pu\PuOperationalActivationFixture as Activation;

/**
 * Fase 7 -- avaliação de prontidão e preflight: dizem o que falta para ativar,
 * por quê, e o que a revisão contratual precisa cobrir, sem gravar nada. Prontidão
 * técnica não autoriza: só a decisão de ativação torna a emissão autorizada.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Activation::withoutAutomaticActivation();
});

function p7rEmission(): Emission
{
    return Fx::emission([[PuEventType::InterestPayment, '2026-03-09']]);
}

/**
 * Contagem de toda tabela do banco: prova de só leitura.
 *
 * @return array<string, int>
 */
function p7rAllTableCounts(): array
{
    $counts = [];

    foreach (Schema::getTableListing(Schema::getCurrentSchemaListing(), false) as $table) {
        $counts[$table] = DB::table($table)->count();
    }

    return $counts;
}

it('reports an emission without PU parameters as not configured', function () {
    $report = app(PuOperationalReadinessService::class)->assess(Emission::factory()->active()->create());

    expect($report->status)->toBe(PuOperationalReadinessStatus::NotConfigured)
        ->and($report->blockers())->toContain('A emissão não tem parâmetros de PU configurados.');
});

it('reports a complete CDI emission on an unconfirmed synthetic calendar as ready for review, listing what to decide', function () {
    $report = app(PuOperationalReadinessService::class)->assess(p7rEmission());

    expect($report->status)->toBe(PuOperationalReadinessStatus::ReadyForReview)
        ->and($report->status->allowsActivation())->toBeTrue()
        ->and($report->blockers())->toBe([])
        ->and(implode(' ', $report->reviewItems()))->toContain('não confirmado')
        // A cauda futura (CDI ainda não divulgado) é informação, não ponto a decidir.
        ->and(implode(' ', $report->reviewItems()))->not->toContain('Somente a parte realizada')
        ->and(collect($report->checks)->firstWhere('key', 'realized_curve_horizon')['result'])->toBe(PuOperationalReadinessReport::RESULT_INFO);
});

it('reports a complete CDI emission on the confirmed market calendar as technically ready but never as authorized without an activation decision', function () {
    PuMarketCalendarFixture::prepare(2026, 2027);
    $emission = p7rEmission();
    $emission->puParameter->update(['calendar_code' => BusinessCalendarRegistry::MARKET_CALENDAR]);
    $emission = $emission->fresh();
    $report = app(PuOperationalReadinessService::class)->assess($emission);

    expect($report->status)->toBe(PuOperationalReadinessStatus::TechnicallyReady)
        ->and($report->status->allowsActivation())->toBeTrue()
        ->and($report->blockers())->toBe([])
        ->and($report->indexerCertification)->toBe(PuIndexerCertification::ConditionallyCertified)
        ->and($report->contractualChecklist)->toBe(PuOperationalReadinessService::CONTRACTUAL_CHECKLIST);

    Activation::activate($emission->id);

    expect(app(PuOperationalReadinessService::class)->assess($emission)->status)->toBe(PuOperationalReadinessStatus::OperationallyAuthorized);

    config([PuOperationsConfig::ENABLED_KEY => false]);

    expect(app(PuOperationalReadinessService::class)->assess($emission)->status)->toBe(PuOperationalReadinessStatus::TechnicallyReady);
});

it('blocks readiness on a missing realized CDI, an unsupported contractual effect and an uncertified indexer', function () {
    $missingCdi = p7rEmission();
    IndexRate::query()->whereDate('rate_date', '2026-03-04')->delete();
    $missingReport = app(PuOperationalReadinessService::class)->assess($missingCdi);

    $unsupported = Fx::emission([[PuEventType::InterestPayment, '2026-03-09'], [PuEventType::Default, '2026-03-11', ['financial_effect' => ['default_rate' => '1.00']]]]);
    Fx::publish('2026-03-04', '2026-03-04');
    $unsupportedReport = app(PuOperationalReadinessService::class)->assess($unsupported);

    $prefixed = p7rEmission();
    $prefixed->puParameter->update(['indexer' => PuIndexer::Prefixed->value, 'annual_rate' => '12.00000000', 'spread_rate' => null]);
    $prefixedReport = app(PuOperationalReadinessService::class)->assess($prefixed->fresh());

    expect($missingReport->status)->toBe(PuOperationalReadinessStatus::NotReady)
        ->and(implode(' ', $missingReport->blockers()))->toContain('2026-03-04')
        ->and($unsupportedReport->status)->toBe(PuOperationalReadinessStatus::NotReady)
        ->and(implode(' ', $unsupportedReport->blockers()))->toContain('Inadimplemento')
        ->and($prefixedReport->status)->toBe(PuOperationalReadinessStatus::NotReady)
        ->and($prefixedReport->indexerCertification)->toBe(PuIndexerCertification::NotCertified)
        ->and(implode(' ', $prefixedReport->blockers()))->toContain('INDEXER_NOT_OPERATIONALLY_HOMOLOGATED');
});

it('blocks an informed extraordinary amortization without a contractual event and asks for a decision on premium ownership', function () {
    $extra = p7rEmission();
    Payment::query()->create(['emission_id' => $extra->id, 'payment_date' => '2026-03-09', 'premium_value' => '0.00', 'interest_value' => '0.00', 'amortization_value' => '0.00', 'extra_amortization_value' => '50.00']);
    $extraReport = app(PuOperationalReadinessService::class)->assess($extra);

    $premium = p7rEmission();
    DB::table('emission_pu_parameters')->where('emission_id', $premium->id)->update([
        'first_coupon_pre_integralization_premium_enabled' => true,
        'first_coupon_pre_integralization_business_days' => 2,
    ]);
    Payment::query()->create(['emission_id' => $premium->id, 'payment_date' => '2026-03-09', 'premium_value' => '7.00', 'interest_value' => '0.00', 'amortization_value' => '0.00', 'extra_amortization_value' => '0.00']);
    $premiumCheck = collect(app(PuOperationalReadinessService::class)->assess($premium->fresh())->checks)->firstWhere('key', 'informed_schedule_effects');

    expect($extraReport->status)->toBe(PuOperationalReadinessStatus::NotReady)
        ->and(implode(' ', $extraReport->blockers()))->toContain('amortização extraordinária em 09/03/2026 sem evento contratual')
        ->and($premiumCheck['result'])->toBe(PuOperationalReadinessReport::RESULT_REVIEW)
        ->and(implode(' ', $premiumCheck['details']))->toContain('nunca os dois');
});

it('assesses readiness without writing anything, not even the calendar rows the prerequisites complete on their own', function () {
    $emission = p7rEmission();
    BusinessCalendarDate::query()->where('calendar_code', 'B3')->whereDate('calendar_date', '>=', '2026-06-01')->delete();
    $before = p7rAllTableCounts();

    app(PuOperationalReadinessService::class)->assess($emission);

    expect(p7rAllTableCounts())->toBe($before);
});

it('runs the preflight read-only and fails while something blocks the activation', function () {
    $emission = p7rEmission();
    IndexRate::query()->whereDate('rate_date', '2026-03-04')->delete();
    $before = p7rAllTableCounts();

    $report = app(PuOperationalPreflightService::class)->run();
    $items = collect($report['items'])->keyBy('requirement');

    expect(p7rAllTableCounts())->toBe($before)
        ->and($report['globally_enabled'])->toBeTrue()
        ->and($report['emissions'][0]['emission_id'])->toBe($emission->id)
        ->and($report['emissions'][0]['status'])->toBe(PuOperationalReadinessStatus::NotReady->value)
        ->and($items['Certificação do indexador PREFIXED']['evidence'])->toContain('bloqueado')
        ->and($items['Certificação do indexador IPCA']['evidence'])->toContain('bloqueado')
        ->and($items['Retenção da trilha de auditoria do PU']['result'])->toBe(PuOperationalPreflightService::OK)
        ->and($items['Backup e restauração do banco (tabelas do PU)']['result'])->toBe(PuOperationalPreflightService::MANUAL)
        ->and($items['Entrega de alertas']['result'])->toBe(PuOperationalPreflightService::ATTENTION);

    $this->artisan('pu:operations:preflight')->assertSuccessful();
});

it('blocks the preflight on an exhausted obligation refresh or an open critical incident', function () {
    p7rEmission();
    DB::table('pu_obligation_refresh_requests')->insert([
        'emission_id' => 999,
        'trigger' => 'index_rate_corrected',
        'status' => 'exhausted',
        'correlation_id' => (string) Str::uuid(),
        'requested_at' => now(),
        'attempts' => 5,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $report = app(PuOperationalPreflightService::class)->run();

    expect($report['blocked'])->toBeTrue()
        ->and(collect($report['items'])->firstWhere('requirement', 'Atualização durável das obrigações')['result'])->toBe(PuOperationalPreflightService::BLOCKED);

    $this->artisan('pu:operations:preflight', ['--json' => true])->assertFailed();
});

it('tells in the preflight that nothing runs while PU operations are disabled in the environment', function () {
    config([PuOperationsConfig::ENABLED_KEY => false]);

    $this->artisan('pu:operations:preflight')
        ->expectsOutputToContain('DESLIGADAS')
        ->assertSuccessful();
});
