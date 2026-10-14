<?php

declare(strict_types=1);

namespace Tests\Support\Pu;

use App\Domain\PuCalculator\Enums\PuOperationalActivationStatus;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuOperationalStatusChange;
use App\Models\EmissionPuParameter;

/**
 * Contexto operacional SIMULADO do PU para a suíte (Fase 7).
 *
 * Em produção, nenhuma emissão entra no pipeline do PU sem uma decisão
 * autorizada de ativação. A suíte anterior à Fase 7 (milhares de testes de
 * engine, governança, extensão, obrigações e liquidação) testa o que acontece
 * DENTRO do pipeline; para ela, este fixture grava explicitamente uma decisão de
 * ativação de teste (`decided_via = test_fixture`) sempre que um parâmetro de PU
 * nasce, ou quando uma versão de curva nasce direto pela factory numa emissão sem
 * decisão -- o equivalente, no teste, a quem ativou a emissão. O código de produção
 * não tem atalho de ambiente: o portão é o mesmo, e é ele que lê esta decisão.
 *
 * Os testes do portão (Fase 7) desligam o automático e decidem a ativação
 * caso a caso ({@see self::withoutAutomaticActivation()}, {@see self::activate()}).
 * Processos filhos dos testes `mysql` enxergam a decisão porque ela é uma linha
 * do banco, gravada pelo processo pai antes de dispará-los.
 */
final class PuOperationalActivationFixture
{
    private static bool $automatic = true;

    /**
     * Chamado pelo `TestCase` a cada teste: religa o automático e registra o
     * ouvinte no despachante de eventos da aplicação recém-criada.
     */
    public static function register(): void
    {
        self::$automatic = true;

        EmissionPuParameter::created(static function (EmissionPuParameter $parameter): void {
            if (self::$automatic) {
                self::activate((int) $parameter->emission_id);
            }
        });

        // Versão de curva criada direto pela factory (sem parâmetros): o teste
        // monta um pipeline que já estaria em operação.
        EmissionPuCurveVersion::created(static function (EmissionPuCurveVersion $version): void {
            if (self::$automatic && ! EmissionPuOperationalStatusChange::query()->where('emission_id', $version->emission_id)->exists()) {
                self::activate((int) $version->emission_id);
            }
        });
    }

    public static function withoutAutomaticActivation(): void
    {
        self::$automatic = false;
    }

    public static function activate(int $emissionId, ?int $decidedBy = null): EmissionPuOperationalStatusChange
    {
        return self::record($emissionId, PuOperationalActivationStatus::Activated, $decidedBy);
    }

    public static function suspend(int $emissionId, ?int $decidedBy = null): EmissionPuOperationalStatusChange
    {
        return self::record($emissionId, PuOperationalActivationStatus::Suspended, $decidedBy);
    }

    private static function record(int $emissionId, PuOperationalActivationStatus $status, ?int $decidedBy): EmissionPuOperationalStatusChange
    {
        $previous = EmissionPuOperationalStatusChange::query()->where('emission_id', $emissionId)->orderByDesc('id')->value('status');

        return EmissionPuOperationalStatusChange::query()->create([
            'emission_id' => $emissionId,
            'status' => $status,
            'previous_status' => $previous ?? PuOperationalActivationStatus::Inactive,
            'reason' => 'Contexto operacional simulado da suíte de testes.',
            'approval_reference' => 'TEST-FIXTURE',
            'decided_by' => $decidedBy,
            'decided_via' => 'test_fixture',
            'decided_at' => now(),
        ]);
    }
}
