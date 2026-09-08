<?php

declare(strict_types=1);

namespace Tests\Support\SalesBoards;

use App\Enums\SalesBoardRolloutRecipientRole;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\SalesBoard;
use App\Models\SalesBoardRolloutHomologation;
use App\Models\SalesDiscountPolicy;
use App\Models\User;
use App\Services\SalesBoards\SalesBoardRolloutActivationService;
use App\Services\SalesBoards\SalesBoardRolloutHomologationService;
use App\Services\SalesBoards\SalesBoardRolloutRecipientDirectory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;

/**
 * Monta Emissões prontas para homologar e ativar.
 */
final class RolloutFixture
{
    public const START_MONTH = '2026-08-01';

    public const COMPARISON_MONTH = '2026-07-01';

    public const REASON = 'Homologação revisada com a operação e a Gestão.';

    /**
     * Uma Emissão com N empreendimentos deriváveis na competência de comparação.
     *
     * @return array{emission: Emission, constructions: list<Construction>}
     */
    public static function emission(int $constructions = 2, string $prefix = 'A'): array
    {
        $emission = Emission::factory()->create(['status' => 'active']);
        $created = [];

        foreach (range(1, $constructions) as $index) {
            $created[] = self::construction($emission, $prefix.$index);
        }

        return ['emission' => $emission, 'constructions' => $created];
    }

    public static function construction(Emission $emission, string $unitPrefix): Construction
    {
        $construction = Construction::factory()->create(['emission_id' => $emission->getKey()]);

        SalesDiscountPolicy::factory()->forConstruction($construction)
            ->effectiveFrom('2020-01-01')->allowing('10.00')->create();

        DerivationFixture::unit($construction, $unitPrefix.'01');
        DerivationFixture::unit($construction, $unitPrefix.'02');

        return $construction;
    }

    /**
     * Registra a posição legada que a homologação vai comparar.
     *
     * Os dois empreendimentos derivam 2 unidades em estoque a 500.000 cada, ou
     * seja 1.000.000. Passar exatamente isso produz `Matched`; passar outra
     * coisa produz `Different`.
     */
    public static function legacyBoard(
        Construction $construction,
        string $referenceMonth = self::COMPARISON_MONTH,
        int $stockUnits = 2,
        string $stockValue = '1000000.00',
    ): SalesBoard {
        return SalesBoard::factory()->create([
            'emission_id' => $construction->emission_id,
            'construction_id' => $construction->getKey(),
            'reference_month' => $referenceMonth,
            'stock_units' => $stockUnits,
            'financed_units' => 0,
            'paid_units' => 0,
            'exchanged_units' => 0,
            'stock_value' => $stockValue,
            'financed_value' => '0.00',
            'paid_value' => '0.00',
            'exchanged_value' => '0.00',
        ]);
    }

    public static function open(
        Emission $emission,
        ?User $actor = null,
        string $startMonth = self::START_MONTH,
        bool $autoOpen = false,
    ): SalesBoardRolloutHomologation {
        return app(SalesBoardRolloutHomologationService::class)->open(
            $emission->fresh(),
            CarbonImmutable::parse($startMonth),
            $actor ?? User::factory()->create(),
            $autoOpen,
        );
    }

    /**
     * Cobre os dois papéis com destinatários operacionais.
     *
     * @return array{operational: User, management: User}
     */
    public static function recipients(Emission $emission, ?User $actor = null): array
    {
        $directory = app(SalesBoardRolloutRecipientDirectory::class);

        $operational = self::operationalUser();
        $management = self::operationalUser();

        $directory->add($emission, SalesBoardRolloutRecipientRole::Operational, $operational, $actor);
        $directory->add($emission, SalesBoardRolloutRecipientRole::Management, $management, $actor);

        return ['operational' => $operational, 'management' => $management];
    }

    public static function operationalUser(): User
    {
        return User::factory()->create(['approved_at' => now()]);
    }

    /**
     * Marca as duas atestações de impacto.
     */
    public static function reviewImpacts(SalesBoardRolloutHomologation $homologation, ?User $actor = null): void
    {
        $actor ??= User::factory()->create();
        $service = app(SalesBoardRolloutHomologationService::class);

        $service->markGuaranteesReviewed($homologation, $actor);
        $service->markMonthlyReportReviewed($homologation->fresh(), $actor);
    }

    public static function approve(
        SalesBoardRolloutHomologation $homologation,
        ?User $actor = null,
        string $reason = self::REASON,
    ): SalesBoardRolloutHomologation {
        return app(SalesBoardRolloutHomologationService::class)
            ->approve($homologation->fresh(), $actor ?? User::factory()->create(), $reason);
    }

    public static function activate(
        Emission $emission,
        SalesBoardRolloutHomologation $homologation,
        ?User $actor = null,
        string $reason = 'Ativação acordada com a operação.',
    ): Emission {
        return app(SalesBoardRolloutActivationService::class)
            ->activate($emission->fresh(), $homologation->fresh(), $actor ?? User::factory()->create(), $reason);
    }

    public static function returnToLegacy(
        Emission $emission,
        ?User $actor = null,
        string $reason = 'Retorno ao legado para revisar o cadastro.',
    ): Emission {
        return app(SalesBoardRolloutActivationService::class)
            ->returnToLegacy($emission->fresh(), $actor ?? User::factory()->create(), $reason);
    }

    /**
     * O caminho feliz inteiro: homologar, cobrir papéis, atestar e aprovar.
     */
    public static function approvedHomologation(
        Emission $emission,
        ?User $actor = null,
        string $startMonth = self::START_MONTH,
        bool $autoOpen = false,
    ): SalesBoardRolloutHomologation {
        $actor ??= User::factory()->create();

        $homologation = self::open($emission, $actor, $startMonth, $autoOpen);

        self::recipients($emission, $actor);
        self::reviewImpacts($homologation, $actor);

        return self::approve($homologation, $actor);
    }

    /**
     * Liga o interruptor global da Fase F, mantendo o provider de banco.
     */
    public static function enableGlobalAutomation(): void
    {
        Config::set('sales_board.automation.enabled', true);
        Config::set('sales_board.automation.targets', []);
    }
}
