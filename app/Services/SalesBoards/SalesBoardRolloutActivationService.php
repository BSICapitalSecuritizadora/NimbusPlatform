<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\Enums\SalesBoardRolloutEventType;
use App\Enums\SalesBoardSource;
use App\Exceptions\SalesBoardRolloutException;
use App\Models\Emission;
use App\Models\SalesBoardRolloutEvent;
use App\Models\SalesBoardRolloutHomologation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Liga e desliga o modo automatizado de uma Emissão.
 *
 * A ativação **não** gera ciclo. Ela muda o modo e para aí; a próxima execução
 * horária da Fase F descobre as competências devidas e faz o resto. Chamar o
 * orquestrador daqui misturaria duas responsabilidades e faria uma ativação
 * feita às 23h de um dia 20 produzir, dentro da mesma transação, três
 * competências de catch-up que ninguém pediu naquele instante.
 *
 * O retorno ao legado **não** apaga nada. Ciclos, versões, revisões, publicações
 * e quadros publicados permanecem, e o leitor continua enxergando-os. O rollout
 * decide apenas qual workflow produz os **próximos** quadros.
 */
class SalesBoardRolloutActivationService
{
    public function __construct(
        private readonly SalesBoardRolloutHomologationService $homologations,
        private readonly SalesBoardRolloutAssessmentService $assessment,
        private readonly SalesBoardRolloutRecipientDirectory $recipients,
    ) {}

    /**
     * Legacy → Automated.
     *
     * Tudo é relido sob lock: a homologação pode ter sido aprovada horas antes,
     * e nesse intervalo um quadro manual pode ter aparecido na competência
     * inicial, um empreendimento pode ter entrado na Emissão, um destinatário
     * pode ter sido desativado. Aprovar não é prometer que o mundo ficou parado.
     */
    public function activate(
        Emission $emission,
        SalesBoardRolloutHomologation $homologation,
        ?User $actor,
        string $reason,
    ): Emission {
        if ($actor === null) {
            throw SalesBoardRolloutException::actorRequired();
        }

        $reason = $this->normalizeReason($reason);

        if ($reason === null) {
            throw SalesBoardRolloutException::reasonRequired();
        }

        return DB::transaction(function () use ($emission, $homologation, $actor, $reason): Emission {
            $locked = Emission::query()
                ->whereKey($emission->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $homologation = SalesBoardRolloutHomologation::query()
                ->whereKey($homologation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->usesAutomatedSalesBoard()) {
                throw SalesBoardRolloutException::emissionAlreadyAutomated();
            }

            if ((int) $homologation->emission_id !== (int) $locked->getKey()) {
                throw SalesBoardRolloutException::homologationDoesNotBelongToEmission();
            }

            if (! $homologation->isApproved()) {
                throw SalesBoardRolloutException::homologationNotApproved($homologation->status);
            }

            /**
             * Uma homologação sustenta uma ativação. Reativar depois de um
             * retorno ao legado exige revisar os fatos de novo -- e nesse
             * intervalo eles certamente mudaram, porque foi por isso que a
             * automação foi desligada.
             */
            if ($homologation->wasActivated()) {
                throw SalesBoardRolloutException::homologationAlreadyUsed();
            }

            $this->assertStillApplicable($homologation, $locked);

            $start = $homologation->startsAt();

            $locked->forceFill([
                'sales_board_source' => SalesBoardSource::Automated,
                'sales_board_automation_start_reference_month' => $start->toDateString(),
                'sales_board_auto_open_builder_review' => (bool) $homologation->auto_open_builder_review,
                'sales_board_active_homologation_id' => $homologation->getKey(),
            ])->save();

            $homologation->forceFill(['activated_at' => CarbonImmutable::now()])->save();

            $this->recordEvent(
                emission: $locked,
                type: SalesBoardRolloutEventType::Activated,
                from: SalesBoardSource::Legacy,
                to: SalesBoardSource::Automated,
                homologation: $homologation,
                startMonth: $start,
                reason: $reason,
                actor: $actor,
            );

            return $locked->refresh();
        });
    }

    /**
     * Automated → Legacy.
     *
     * Interrompe novas tentativas automáticas. Nada é apagado, nada é
     * despublicado, nenhum ciclo em andamento é cancelado -- o trabalho humano
     * que estiver a meio caminho continua exatamente onde estava.
     */
    public function returnToLegacy(Emission $emission, ?User $actor, string $reason): Emission
    {
        if ($actor === null) {
            throw SalesBoardRolloutException::actorRequired();
        }

        $reason = $this->normalizeReason($reason);

        if ($reason === null) {
            throw SalesBoardRolloutException::reasonRequired();
        }

        return DB::transaction(function () use ($emission, $actor, $reason): Emission {
            $locked = Emission::query()
                ->whereKey($emission->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->usesAutomatedSalesBoard()) {
                throw SalesBoardRolloutException::emissionNotAutomated();
            }

            $homologationId = $locked->sales_board_active_homologation_id;
            $start = $locked->automationStartsAt();

            $locked->forceFill([
                'sales_board_source' => SalesBoardSource::Legacy,
                'sales_board_automation_start_reference_month' => null,
                'sales_board_auto_open_builder_review' => false,
                'sales_board_active_homologation_id' => null,
            ])->save();

            $this->recordEvent(
                emission: $locked,
                type: SalesBoardRolloutEventType::ReturnedToLegacy,
                from: SalesBoardSource::Automated,
                to: SalesBoardSource::Legacy,
                homologation: $homologationId === null
                    ? null
                    : SalesBoardRolloutHomologation::query()->find($homologationId),
                startMonth: $start,
                reason: $reason,
                actor: $actor,
            );

            return $locked->refresh();
        });
    }

    /**
     * A homologação aprovada ainda descreve o mundo?
     */
    private function assertStillApplicable(SalesBoardRolloutHomologation $homologation, Emission $emission): void
    {
        $currentScope = $this->assessment->scopeHash(
            $this->assessment->constructionsOf($emission)->keys()->all()
        );

        if ($currentScope !== (string) $homologation->construction_scope_hash) {
            throw SalesBoardRolloutException::scopeChanged();
        }

        /**
         * O conflito é reconferido contra o banco de agora -- um quadro manual
         * criado depois da aprovação é exatamente o caso que isto existe para
         * pegar -- e a reconferência é **somente leitura**: a homologação
         * aprovada é imutável, e reavaliá-la aqui tentaria reescrever o retrato
         * que a Gestão revisou.
         */
        $conflicts = $this->assessment->legacyConflictsFor(
            $emission,
            $homologation->startsAt(),
        );

        if ($conflicts !== []) {
            throw SalesBoardRolloutException::legacyBoardConflict(
                $conflicts,
                $homologation->startMonthLabel(),
            );
        }

        $this->recipients->assertConfigured($emission);
    }

    private function recordEvent(
        Emission $emission,
        SalesBoardRolloutEventType $type,
        SalesBoardSource $from,
        SalesBoardSource $to,
        ?SalesBoardRolloutHomologation $homologation,
        ?CarbonImmutable $startMonth,
        string $reason,
        User $actor,
    ): void {
        SalesBoardRolloutEvent::query()->create([
            'emission_id' => $emission->getKey(),
            'event_type' => $type,
            'from_source' => $from,
            'to_source' => $to,
            'sales_board_rollout_homologation_id' => $homologation?->getKey(),
            'start_reference_month' => $startMonth?->toDateString(),
            'reason' => $reason,
            'actor_user_id' => $actor->getKey(),
        ]);
    }

    private function normalizeReason(?string $reason): ?string
    {
        $reason = trim(strip_tags((string) $reason));

        if (mb_strlen($reason) < SalesBoardRolloutHomologationService::MINIMUM_REASON_LENGTH) {
            return null;
        }

        return mb_substr($reason, 0, SalesBoardRolloutHomologationService::MAXIMUM_REASON_LENGTH);
    }
}
