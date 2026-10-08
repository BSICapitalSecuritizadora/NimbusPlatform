<?php

namespace App\Services;

use App\DTOs\Measurements\MeasurementPhysicalProgress;
use App\DTOs\Measurements\MeasurementPlanVersionComparison;
use App\Enums\MeasurementPlanRevisionCategory;
use App\Enums\MeasurementPlanVersionStatus;
use App\Enums\OperationStatus;
use App\Exceptions\MeasurementWorkflowException;
use App\Models\Measurement;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\MeasurementPlanVersion;
use App\Models\Operation;
use App\Models\User;
use App\Support\ActivityLog\LogBatch;
use App\Support\BusinessTime;
use App\Support\Money\IntegerMoney;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * A porta de escrita do plano de medição e das versões dele.
 *
 * Toda escrita abre a própria transação e trava a Operation como primeira
 * instrução, com o `operation_id` já carregado: a ordem canônica do módulo
 * ({@see MeasurementWorkflow}) -- Operation, depois o plano, depois as versões
 * do plano em ordem de id, depois as linhas. No MySQL em REPEATABLE READ a
 * fotografia da transação nasce depois do lock e enxerga toda medição, toda
 * aprovação e toda ativação que a Operation serializou antes; o avanço físico e
 * as medições de pé que a ativação confere são os atuais. A criação de
 * medição, a aprovação da Engenharia, a recusa terminal, o pagamento e a
 * Finalização seguram a mesma Operation, então nenhum deles corre no meio de
 * uma revisão ou de uma ativação.
 *
 * Versão vigente e substituída nunca são editadas: a mudança nasce num
 * rascunho ({@see self::createRevision()}) e vale quando ele é ativado
 * ({@see self::activate()}), que substitui a vigente na mesma transação. O
 * rascunho tem um contador (`revision`) que cada gravação incrementa: quem
 * ativa ou edita confirma o rascunho que viu, e uma submissão velha é recusada
 * em vez de ativar ou sobrescrever o que outra pessoa mudou.
 *
 * Vigência: a versão vale a partir da competência (mês, no calendário de
 * negócio) em que é ativada -- a ativação é o início da vigência, nem antes,
 * nem depois. A versão anterior vale até o último dia do mês anterior. Uma
 * revisão nunca replaneja competência que já tem medição de pé (aberta,
 * aprovada, paga ou finalizada), nem reescreve as competências anteriores à
 * vigência: a vigente traz, igual, o previsto do passado.
 */
class MeasurementPlanVersionService
{
    public const STALE_DRAFT_MESSAGE = 'O rascunho da %s foi alterado por outra pessoa desde que você o abriu. Recarregue a página e confira as mudanças antes de continuar.';

    public const NOT_A_DRAFT_MESSAGE = 'A %s do plano não é mais rascunho (agora: %s). Recarregue a página.';

    public const STALE_BASE_MESSAGE = 'A versão vigente do plano mudou desde que você abriu a revisão (agora é a %s). Recarregue a página antes de revisar o plano.';

    public const TERMINAL_OPERATION_MESSAGE = 'A operação está %s: o plano de medição não muda mais (plano novo, revisão, ativação ou exclusão). Reabra a operação para replanejar.';

    public function __construct(private MeasurementPhysicalProgressService $physicalProgress) {}

    /**
     * Competência a partir da qual uma versão ativada agora passa a valer: o
     * primeiro dia do mês corrente no calendário de negócio (BRT).
     */
    public static function activationCompetence(): CarbonImmutable
    {
        return CarbonImmutable::parse(BusinessTime::dateString())->startOfMonth();
    }

    /**
     * Cria o plano de uma obra na operação, com a V1 em rascunho: o Fundo de
     * Obra e o cronograma informados vão para ela.
     *
     * @param  array{name: string, construction_id?: mixed, is_default?: mixed, initial_incurred_amount?: mixed, initial_physical_progress_percent?: mixed, initial_physical_progress_reference_date?: mixed}  $planData
     * @param  array{construction_fund_amount?: mixed}  $versionData
     * @param  list<array<string, mixed>>  $lines
     */
    public function createPlan(Operation|int $operation, User $actor, array $planData, array $versionData = [], array $lines = []): MeasurementPlanSet
    {
        $operationId = $operation instanceof Operation ? (int) $operation->getKey() : $operation;

        return DB::transaction(function () use ($operationId, $actor, $planData, $versionData, $lines): MeasurementPlanSet {
            $locked = $this->lockOperation($operationId);
            $this->authorize($actor, $locked);
            $this->assertOperationAcceptsPlanChanges($locked);

            return app(LogBatch::class)->withinBatch(fn (): MeasurementPlanSet => $this->createPlanUnderLock($locked, $actor, $planData, $versionData, $lines));
        });
    }

    /**
     * Dados do plano -- nome, padrão e, enquanto o plano não valeu nem recebeu
     * medição, obra e incorrido inicial. O cronograma e o Fundo de Obra são da
     * versão e mudam por revisão.
     *
     * @param  array{name?: mixed, construction_id?: mixed, is_default?: mixed, initial_incurred_amount?: mixed}  $planData
     */
    public function updatePlan(MeasurementPlanSet $planSet, User $actor, array $planData): MeasurementPlanSet
    {
        return DB::transaction(function () use ($planSet, $actor, $planData): MeasurementPlanSet {
            $operation = $this->lockOperation((int) $planSet->operation_id);
            $this->authorize($actor, $operation);
            $locked = $this->lockPlanSet((int) $planSet->getKey(), $operation);

            $locked->fill(array_intersect_key($planData, array_flip(['name', 'construction_id', 'is_default', 'initial_incurred_amount'])));
            $locked->save();

            return $locked;
        });
    }

    /**
     * Exclui o plano cadastrado por engano, com as versões e as linhas dele --
     * só sai plano sem histórico de medição ({@see MeasurementPlanSet}, o
     * gancho de exclusão recusa o resto) --, com a Operation travada antes, a
     * permissão conferida contra a linha travada e a operação ainda aberta: na
     * concluída ou cancelada o histórico das versões fica como está. Plano que
     * outra pessoa já excluiu não é recusa: o pedido está cumprido.
     */
    public function deletePlan(MeasurementPlanSet $planSet, User $actor): bool
    {
        return DB::transaction(function () use ($planSet, $actor): bool {
            $operation = $this->lockOperation((int) $planSet->operation_id);
            $this->authorize($actor, $operation);
            $this->assertOperationAcceptsPlanChanges($operation);

            $locked = MeasurementPlanSet::query()
                ->where('operation_id', $operation->getKey())
                ->whereKey($planSet->getKey())
                ->lockForUpdate()
                ->first();

            return ! $locked instanceof MeasurementPlanSet || (bool) $locked->deletedBy($actor)->delete();
        });
    }

    /**
     * Abre a revisão do plano: um rascunho com o próximo número, copiado da
     * versão vigente -- Fundo de Obra e cronograma previsto, com a mesma
     * linhagem em cada medição prevista. A vigente não muda. Não se copia
     * execução: o realizado gravado nas linhas, as medições, os pagamentos e
     * as aprovações continuam onde estão.
     *
     * `$expectedActiveVersionId` é a vigente que a pessoa viu ao abrir a
     * revisão: se outra pessoa ativou outra versão nesse meio-tempo, o motivo
     * informado não se refere mais à versão em vigor, e a revisão é recusada.
     *
     * @param  array{revision_category?: mixed, revision_reason?: mixed}  $data
     */
    public function createRevision(MeasurementPlanSet $planSet, User $actor, array $data = [], ?int $expectedActiveVersionId = null): MeasurementPlanVersion
    {
        return DB::transaction(function () use ($planSet, $actor, $data, $expectedActiveVersionId): MeasurementPlanVersion {
            $operation = $this->lockOperation((int) $planSet->operation_id);
            $this->authorize($actor, $operation);
            $this->assertOperationAcceptsPlanChanges($operation);
            $locked = $this->lockPlanSet((int) $planSet->getKey(), $operation);
            $versions = $this->lockVersions($locked);

            $active = $versions->first(fn (MeasurementPlanVersion $version): bool => $version->isActive());
            $draft = $versions->first(fn (MeasurementPlanVersion $version): bool => $version->isDraft());

            // Sem vigente, o único rascunho possível é o da V1: o caminho é
            // ativá-lo, não cancelá-lo (o da V1 não se cancela).
            if (! $active instanceof MeasurementPlanVersion) {
                throw new MeasurementWorkflowException('O plano ainda não tem versão vigente: ative a V1 antes de revisá-lo.', [
                    'plan_set_id' => $locked->getKey(),
                ]);
            }

            if ($draft instanceof MeasurementPlanVersion) {
                throw new MeasurementWorkflowException(sprintf(
                    'O plano já tem a %s em rascunho: conclua, ative ou cancele esse rascunho antes de abrir outra revisão.',
                    $draft->label(),
                ), ['plan_set_id' => $locked->getKey(), 'plan_version_id' => $draft->getKey()]);
            }

            if ($expectedActiveVersionId !== null && (int) $active->getKey() !== $expectedActiveVersionId) {
                throw new MeasurementWorkflowException(sprintf(self::STALE_BASE_MESSAGE, $active->label()), [
                    'plan_set_id' => $locked->getKey(),
                    'expected_active_version_id' => $expectedActiveVersionId,
                    'active_version_id' => $active->getKey(),
                ]);
            }

            $category = $this->category($data['revision_category'] ?? null);
            $reason = $this->text($data['revision_reason'] ?? null);

            // A versão, a cópia de cada linha e o evento da revisão num lote só
            // na trilha.
            return app(LogBatch::class)->withinBatch(function () use ($locked, $versions, $active, $category, $reason, $actor): MeasurementPlanVersion {
                $revision = $locked->versions()->create([
                    'operation_id' => $locked->operation_id,
                    'version_number' => ((int) $versions->max('version_number')) + 1,
                    'status' => MeasurementPlanVersionStatus::Draft,
                    'previous_version_id' => $active->getKey(),
                    'construction_fund_amount' => $active->construction_fund_amount,
                    'revision_category' => $category,
                    'revision_reason' => $reason,
                    'created_by' => $actor->getKey(),
                ]);

                foreach ($active->lines()->orderBy('sequence_number')->orderBy('id')->get() as $line) {
                    $copy = $revision->lines()->make([
                        'sequence_number' => $line->sequence_number,
                        'planned_monthly_percent' => $line->planned_monthly_percent,
                        'planned_cumulative_percent' => $line->planned_cumulative_percent,
                        'measurement_date' => $line->measurement_date?->toDateString(),
                    ]);
                    $copy->forceFill(['lineage_key' => $line->lineage_key])->save();
                }

                $revision = $revision->fresh();
                $this->audit($revision, 'plan_version_created', $actor);

                return $revision;
            });
        });
    }

    /**
     * Grava o rascunho: Fundo de Obra, categoria e justificativa e, quando
     * informado, o cronograma inteiro (linhas existentes por id, novas sem id,
     * as ausentes saem). Recusa versão que deixou de ser rascunho e rascunho
     * alterado desde que a pessoa o abriu.
     *
     * @param  array{construction_fund_amount?: mixed, revision_category?: mixed, revision_reason?: mixed}  $data
     * @param  list<array<string, mixed>>|null  $lines
     */
    public function updateDraft(MeasurementPlanVersion $version, User $actor, array $data, ?array $lines, int $expectedRevision): MeasurementPlanVersion
    {
        return DB::transaction(function () use ($version, $actor, $data, $lines, $expectedRevision): MeasurementPlanVersion {
            $locked = $this->lockDraft($version, $actor, $expectedRevision);

            if (array_key_exists('construction_fund_amount', $data)) {
                $locked->construction_fund_amount = $this->money($data['construction_fund_amount'], 'construction_fund_amount');
            }

            if (array_key_exists('revision_category', $data)) {
                $locked->revision_category = $this->category($data['revision_category']);
            }

            if (array_key_exists('revision_reason', $data)) {
                $locked->revision_reason = $this->text($data['revision_reason']);
            }

            // O cronograma e o rascunho num lote só na trilha: uma gravação.
            return app(LogBatch::class)->withinBatch(function () use ($locked, $lines): MeasurementPlanVersion {
                if ($lines !== null) {
                    $this->syncLines($locked, $lines);
                }

                $locked->revision = (int) $locked->revision + 1;
                $locked->save();

                return $locked->fresh();
            });
        });
    }

    /**
     * Gera medições previstas em sequência no rascunho, uma por mês a partir
     * do informado, com previsto zero para preencher depois.
     */
    public function addDraftLines(MeasurementPlanVersion $version, User $actor, int $count, ?string $startMonth, int $expectedRevision): MeasurementPlanVersion
    {
        if ($count < 1 || $count > 60) {
            throw ValidationException::withMessages(['count' => 'Informe de 1 a 60 medições previstas.']);
        }

        $start = $this->competence($startMonth, 'start_date') ?? self::activationCompetence()->toDateString();

        return DB::transaction(function () use ($version, $actor, $count, $start, $expectedRevision): MeasurementPlanVersion {
            $locked = $this->lockDraft($version, $actor, $expectedRevision);
            $lastSequence = (int) $locked->lines()->max('sequence_number');
            $first = CarbonImmutable::parse($start);

            for ($index = 1; $index <= $count; $index++) {
                $locked->lines()->create([
                    'sequence_number' => $lastSequence + $index,
                    'measurement_date' => $first->addMonthsNoOverflow($index - 1)->toDateString(),
                    'planned_monthly_percent' => 0,
                    'planned_cumulative_percent' => 0,
                ]);
            }

            $locked->revision = (int) $locked->revision + 1;
            $locked->save();

            return $locked->fresh();
        });
    }

    /**
     * Ativa o rascunho: a vigente vira substituída e o rascunho vira vigente,
     * na mesma transação -- se qualquer conferência recusar, nada muda e a
     * vigente continua vigente.
     *
     * A versão vale a partir do mês da ativação. O acumulado previsto é
     * recalculado aqui, sob o lock ({@see self::projectSchedule()}): o avanço
     * físico atual mais o previsto que ainda falta medir, em ordem de
     * competência e sequência -- a nova versão planeja só o que resta e não
     * recomeça do zero nem do avanço inicial. O avanço físico em si não muda:
     * ele é do plano (inicial mais as medições com Engenharia vigente) e fica
     * gravado na versão como o retrato do momento da ativação, com a última
     * medição da operação até ali (`last_measurement_id_at_activation`): a
     * medição enviada antes de o plano valer não precisa cobri-lo.
     */
    public function activate(MeasurementPlanVersion $version, User $actor, int $expectedRevision): MeasurementPlanVersion
    {
        return DB::transaction(function () use ($version, $actor, $expectedRevision): MeasurementPlanVersion {
            $operation = $this->lockOperation((int) $version->operation_id);
            $this->authorize($actor, $operation);
            $this->assertOperationAcceptsPlanChanges($operation);
            $planSet = $this->lockPlanSet((int) $version->plan_set_id, $operation);
            $versions = $this->lockVersions($planSet);
            $draft = $this->draftAmong($versions, $version, $expectedRevision);
            $active = $versions->first(fn (MeasurementPlanVersion $candidate): bool => $candidate->isActive());

            if ((int) ($draft->previous_version_id ?? 0) !== (int) ($active?->getKey() ?? 0)) {
                throw new MeasurementWorkflowException(sprintf(
                    'A versão vigente do plano mudou desde que a %s foi aberta: o rascunho não parte mais da versão em vigor. Cancele-o e abra uma nova revisão.',
                    $draft->label(),
                ), ['plan_version_id' => $draft->getKey()]);
            }

            $effectiveFrom = self::activationCompetence();
            $progress = $this->physicalProgress->forPlanSet($planSet);
            $schedule = $this->assertActivatable($planSet, $draft, $active, $progress, $effectiveFrom);
            $lastMeasurementId = Measurement::query()->where('operation_id', $operation->getKey())->max('id');
            $now = now();

            // Um lote só na trilha: o acumulado recalculado, a substituição e a
            // ativação são a mesma decisão, de quem ativou.
            return app(LogBatch::class)->withinBatch(function () use ($active, $draft, $actor, $now, $effectiveFrom, $progress, $schedule, $lastMeasurementId): MeasurementPlanVersion {
                $recalculated = [];

                foreach ($draft->lines()->orderBy('id')->get() as $line) {
                    $cumulative = $schedule['cumulative'][(int) $line->getKey()] ?? null;
                    $drafted = MeasurementPhysicalProgress::basisPoints($line->planned_cumulative_percent);

                    if ($cumulative === null || $drafted === $cumulative) {
                        continue;
                    }

                    $recalculated[] = [
                        'plan_line_id' => (int) $line->getKey(),
                        'lineage_key' => (string) $line->lineage_key,
                        'sequence_number' => (int) $line->sequence_number,
                        'measurement_date' => $line->measurement_date?->toDateString(),
                        'draft_cumulative_percent' => $drafted === null ? null : MeasurementPhysicalProgress::decimal($drafted),
                        'activation_cumulative_percent' => MeasurementPhysicalProgress::decimal($cumulative),
                    ];
                    $line->forceFill(['planned_cumulative_percent' => MeasurementPhysicalProgress::decimal($cumulative)])->save();
                }

                if ($active instanceof MeasurementPlanVersion) {
                    $active->forceFill([
                        'status' => MeasurementPlanVersionStatus::Superseded,
                        'superseded_at' => $now,
                        'superseded_by_version_id' => $draft->getKey(),
                    ])->save();
                    $this->audit($active->fresh(), 'plan_version_superseded', $actor, [
                        'superseded_by_version_id' => (int) $draft->getKey(),
                        'superseded_by_version_number' => (int) $draft->version_number,
                    ]);
                }

                $draft->forceFill([
                    'status' => MeasurementPlanVersionStatus::Active,
                    'effective_from' => $effectiveFrom->toDateString(),
                    'activated_at' => $now,
                    'activated_by' => $actor->getKey(),
                    'activation_progress_percent' => MeasurementPhysicalProgress::decimal($progress->currentBasisPoints()),
                    'last_measurement_id_at_activation' => $lastMeasurementId === null ? null : (int) $lastMeasurementId,
                ])->save();

                $activated = $draft->fresh();
                $this->audit($activated, 'plan_version_activated', $actor, [
                    'physical_progress_percent' => MeasurementPhysicalProgress::decimal($progress->currentBasisPoints()),
                    'remaining_physical_progress_percent' => MeasurementPhysicalProgress::decimal($progress->remainingBasisPoints()),
                    'pending_before_effective_percent' => MeasurementPhysicalProgress::decimal($schedule['pending']),
                    'recalculated_lines' => $recalculated,
                ]);

                return $activated;
            });
        });
    }

    /**
     * Abandona o rascunho de uma revisão. Ele continua gravado, com o número
     * que tinha, como revisão cancelada: nunca fez parte do histórico de
     * medição. O rascunho da V1 não é cancelado -- sem ele o plano ficaria sem
     * versão; plano cadastrado por engano se exclui.
     */
    public function cancel(MeasurementPlanVersion $version, User $actor, string $reason, int $expectedRevision): MeasurementPlanVersion
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['cancellation_reason' => 'Informe o motivo do cancelamento do rascunho.']);
        }

        return DB::transaction(function () use ($version, $actor, $reason, $expectedRevision): MeasurementPlanVersion {
            $operation = $this->lockOperation((int) $version->operation_id);
            $this->authorize($actor, $operation);
            $planSet = $this->lockPlanSet((int) $version->plan_set_id, $operation);
            $draft = $this->draftAmong($this->lockVersions($planSet), $version, $expectedRevision);

            if ((int) $draft->version_number === 1) {
                throw new MeasurementWorkflowException('O rascunho da V1 não é cancelado: sem ele o plano ficaria sem versão. Para desistir do plano, exclua-o.', [
                    'plan_version_id' => $draft->getKey(),
                ]);
            }

            $draft->forceFill([
                'status' => MeasurementPlanVersionStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $actor->getKey(),
                'cancellation_reason' => $reason,
            ])->save();

            $cancelled = $draft->fresh();
            $this->audit($cancelled, 'plan_version_cancelled', $actor);

            return $cancelled;
        });
    }

    /**
     * Sincroniza os planos com os empreendimentos do formulário da operação,
     * sob o lock da Operation: plano novo nasce com a V1 em rascunho (Fundo de
     * Obra e avanço inicial do formulário); o existente só acompanha o nome do
     * empreendimento e, enquanto a V1 ainda for rascunho, o Fundo de Obra dela.
     *
     * O formulário manda, de cada plano existente, o fundo que mostrou
     * (`construction_fund_original`) e o contador do rascunho que leu
     * (`construction_fund_revision`). Fundo igual ao mostrado não é mudança --
     * nada se grava, nem quando outra pessoa mudou o rascunho ou ativou o plano
     * nesse meio-tempo. Fundo diferente só vale no rascunho da V1 que a pessoa
     * viu; plano já ativado recusa (o custo muda por revisão do plano), e
     * rascunho alterado depois de aberto também.
     *
     * @param  array<int, array{construction_id?: mixed, construction_fund_amount?: mixed, construction_fund_original?: mixed, construction_fund_revision?: mixed, initial_physical_progress_percent?: mixed, initial_physical_progress_reference_date?: mixed}>  $developments
     */
    public function syncDevelopmentPlans(Operation $operation, User $actor, array $developments): void
    {
        DB::transaction(function () use ($operation, $actor, $developments): void {
            $locked = $this->lockOperation((int) $operation->getKey());
            $visibility = app(OperationContextVisibilityService::class);
            $visibility->assertOperationPayloadIsVisible($actor, $locked->emission_id, $developments);
            $hasDefault = $locked->planSets()->where('is_default', true)->exists();
            $index = 0;

            foreach ($developments as $development) {
                $constructionId = $development['construction_id'] ?? null;

                if (blank($constructionId)) {
                    continue;
                }

                $construction = $visibility->assertConstructionIsVisibleForOperation($actor, $locked, $constructionId);
                // Leitura comum: a Operation travada já serializa toda escrita
                // de plano dela. Um FOR UPDATE que não acha nada travaria o
                // intervalo do índice -- compartilhado com outras operações -- e
                // o INSERT do plano novo fecharia um deadlock com elas. O plano
                // que existe é travado pela chave.
                $existing = $locked->planSets()
                    ->where('construction_id', $constructionId)
                    ->orderBy('id')
                    ->value('id');
                $planSet = $existing === null ? null : $this->lockPlanSet((int) $existing, $locked);

                if ($planSet instanceof MeasurementPlanSet) {
                    if ($planSet->name !== $construction->development_name) {
                        $planSet->forceFill(['name' => $construction->development_name])->save();
                    }

                    if (array_key_exists('construction_fund_amount', $development)) {
                        $this->syncInitialDraftFund($locked, $planSet, $development);
                    }
                } else {
                    $this->assertOperationAcceptsPlanChanges($locked);
                    $this->createPlanUnderLock($locked, $actor, [
                        'construction_id' => $constructionId,
                        'name' => $construction->development_name,
                        'is_default' => ! $hasDefault && $index === 0,
                        'initial_physical_progress_percent' => $development['initial_physical_progress_percent'] ?? 0,
                        'initial_physical_progress_reference_date' => $development['initial_physical_progress_reference_date'] ?? null,
                    ], ['construction_fund_amount' => $development['construction_fund_amount'] ?? null]);
                }

                $index++;
            }

            $locked->refreshTitleFromConstructions();
        });
    }

    /**
     * O que muda da versão anterior (`base`) para esta. Leitura comum, para a
     * tela; a ativação confere o que importa sob o lock.
     *
     * Para um rascunho, o cronograma mostrado já é o que a ativação gravaria
     * hoje: o acumulado previsto a partir do mês corrente recalculado sobre o
     * avanço físico atual. Para uma versão ativada, vale o que foi gravado na
     * ativação, inclusive o avanço físico daquele momento.
     */
    public function compare(?MeasurementPlanVersion $base, MeasurementPlanVersion $candidate): MeasurementPlanVersionComparison
    {
        $planSet = $candidate->planSet()->firstOrFail();
        $progress = $this->physicalProgress->forPlanSet($planSet);
        $baseLines = $base?->lines()->orderBy('measurement_date')->orderBy('sequence_number')->orderBy('id')->get() ?? new Collection;
        $candidateLines = $candidate->lines()->orderBy('measurement_date')->orderBy('sequence_number')->orderBy('id')->get();
        $currentBasisPoints = $progress->currentBasisPoints();
        $pendingBasisPoints = null;

        if ($candidate->isDraft()) {
            $schedule = $this->projectSchedule($candidateLines, self::activationCompetence(), $progress, (int) $candidate->version_number > 1, $this->monthsMeasuredWithoutThePlan($planSet));
            $pendingBasisPoints = $schedule['pending'];

            foreach ($schedule['cumulative'] as $lineId => $cumulative) {
                $candidateLines->firstWhere('id', $lineId)?->setAttribute('planned_cumulative_percent', MeasurementPhysicalProgress::decimal($cumulative));
            }
        } elseif ($candidate->activation_progress_percent !== null) {
            $currentBasisPoints = (int) MeasurementPhysicalProgress::basisPoints($candidate->activation_progress_percent);
        }

        $baseByLineage = $baseLines->keyBy('lineage_key');
        $candidateByLineage = $candidateLines->keyBy('lineage_key');
        $added = [];
        $removed = [];
        $changed = [];
        $unchanged = 0;

        foreach ($candidateLines as $line) {
            $before = $baseByLineage->get($line->lineage_key);

            if (! $before instanceof MeasurementPlanLine) {
                $added[] = $this->lineSummary($line);

                continue;
            }

            $changes = $this->lineChanges($before, $line);

            if ($changes === []) {
                $unchanged++;
            } else {
                $changed[] = [
                    'lineage_key' => (string) $line->lineage_key,
                    'before' => $this->lineSummary($before),
                    'after' => $this->lineSummary($line),
                    'changes' => $changes,
                ];
            }
        }

        foreach ($baseLines as $line) {
            if (! $candidateByLineage->has($line->lineage_key)) {
                $removed[] = $this->lineSummary($line);
            }
        }

        $openOnBase = $base === null ? [] : Measurement::query()
            ->open()
            ->whereHas('assets', fn (Builder $assets): Builder => $assets->where('plan_version_id', $base->getKey()))
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return new MeasurementPlanVersionComparison(
            base: $base,
            candidate: $candidate,
            baseFundCents: $base?->constructionFundCents(),
            candidateFundCents: $candidate->constructionFundCents(),
            baseCompletion: $this->completion($baseLines),
            candidateCompletion: $this->completion($candidateLines),
            currentBasisPoints: $currentBasisPoints,
            remainingBasisPoints: max(0, MeasurementPhysicalProgress::LIMIT_BASIS_POINTS - $currentBasisPoints),
            baseFinalCumulativeBasisPoints: $this->finalCumulative($baseLines),
            candidateFinalCumulativeBasisPoints: $this->finalCumulative($candidateLines),
            addedLines: $added,
            removedLines: $removed,
            changedLines: $changed,
            unchangedLines: $unchanged,
            openMeasurementIdsOnBase: $openOnBase,
            pendingBeforeEffectiveBasisPoints: $pendingBasisPoints,
        );
    }

    /**
     * Onde o plano está para quem vai revisá-lo ou ativá-lo agora, a partir do
     * cronograma da versão: o avanço físico atual, o previsto de competências
     * anteriores ao mês corrente que ainda não foram medidas (continuam a
     * medir) e o que resta para planejar daqui em diante.
     *
     * @return array{effective_from: CarbonImmutable, current: int, pending: int, remaining_to_plan: int}
     */
    public function planningContext(MeasurementPlanVersion $version): array
    {
        $planSet = $version->planSet()->firstOrFail();
        $progress = $this->physicalProgress->forPlanSet($planSet);
        $effectiveFrom = self::activationCompetence();
        $schedule = $this->projectSchedule($version->lines()->get(), $effectiveFrom, $progress, true, $this->monthsMeasuredWithoutThePlan($planSet));
        $current = $progress->currentBasisPoints();

        return [
            'effective_from' => $effectiveFrom,
            'current' => $current,
            'pending' => $schedule['pending'],
            'remaining_to_plan' => max(0, MeasurementPhysicalProgress::LIMIT_BASIS_POINTS - $current - $schedule['pending']),
        ];
    }

    /**
     * O acumulado previsto que a ativação gravaria hoje em cada linha do
     * rascunho, para a pessoa ver antes de ativar. Leitura comum; a ativação
     * recalcula sob o lock.
     *
     * @return array<int, int> acumulado (basis points) por id de linha
     */
    public function activationPreview(MeasurementPlanVersion $draft): array
    {
        $planSet = $draft->planSet()->firstOrFail();
        $progress = $this->physicalProgress->forPlanSet($planSet);

        return $this->projectSchedule($draft->lines()->get(), self::activationCompetence(), $progress, (int) $draft->version_number > 1, $this->monthsMeasuredWithoutThePlan($planSet))['cumulative'];
    }

    /**
     * @param  array<string, mixed>  $planData
     * @param  array{construction_fund_amount?: mixed}  $versionData
     * @param  list<array<string, mixed>>  $lines
     */
    private function createPlanUnderLock(Operation $operation, User $actor, array $planData, array $versionData, array $lines = []): MeasurementPlanSet
    {
        $planSet = new MeasurementPlanSet(array_intersect_key($planData, array_flip([
            'construction_id',
            'name',
            'is_default',
            'initial_incurred_amount',
            'initial_physical_progress_percent',
            'initial_physical_progress_reference_date',
        ])));
        $planSet->operation()->associate($operation);
        $planSet->firstVersionAuthoredBy($actor);
        $planSet->save();

        $draft = $planSet->draftVersion()->firstOrFail();
        $fund = $this->money($versionData['construction_fund_amount'] ?? null, 'construction_fund_amount');

        if ($fund !== null) {
            $draft->construction_fund_amount = $fund;
            $draft->save();
        }

        if ($lines !== []) {
            $this->syncLines($draft, $lines);
        }

        $this->audit($draft->fresh(), 'plan_version_created', $actor);

        return $planSet;
    }

    /**
     * O formulário reenvia todos os empreendimentos a cada gravação: fundo
     * igual ao mostrado não é mudança e passa em qualquer situação da
     * operação. Fundo diferente é edição do rascunho da V1, e operação
     * concluída ou cancelada não replaneja.
     *
     * @param  array{construction_fund_amount?: mixed, construction_fund_original?: mixed, construction_fund_revision?: mixed}  $development
     */
    private function syncInitialDraftFund(Operation $operation, MeasurementPlanSet $planSet, array $development): void
    {
        $submitted = $this->money($development['construction_fund_amount'] ?? null, 'construction_fund_amount');
        $original = $this->money($development['construction_fund_original'] ?? null, 'construction_fund_amount');

        if (IntegerMoney::cents($submitted) === IntegerMoney::cents($original)) {
            return;
        }

        $this->assertOperationAcceptsPlanChanges($operation);
        $versions = $this->lockVersions($planSet);

        if ($versions->contains(fn (MeasurementPlanVersion $version): bool => $version->isActive())) {
            throw new MeasurementWorkflowException(MeasurementPlanSet::FUND_BELONGS_TO_VERSION_REFUSAL, [
                'plan_set_id' => $planSet->getKey(),
            ]);
        }

        $draft = $versions->first(fn (MeasurementPlanVersion $version): bool => $version->isDraft() && (int) $version->version_number === 1);

        if (! $draft instanceof MeasurementPlanVersion) {
            throw new MeasurementWorkflowException(MeasurementPlanSet::FUND_BELONGS_TO_VERSION_REFUSAL, [
                'plan_set_id' => $planSet->getKey(),
            ]);
        }

        $readRevision = filter_var($development['construction_fund_revision'] ?? null, FILTER_VALIDATE_INT);

        if ($readRevision === false || $readRevision !== (int) $draft->revision) {
            throw new MeasurementWorkflowException(sprintf(self::STALE_DRAFT_MESSAGE, $draft->label()), [
                'plan_version_id' => $draft->getKey(),
            ]);
        }

        $draft->construction_fund_amount = $submitted;
        $draft->revision = (int) $draft->revision + 1;
        $draft->save();
    }

    /**
     * O cronograma do rascunho passa a ser exatamente o informado: linhas com
     * id (desta versão) são atualizadas, sem id nascem com linhagem nova, as
     * que sumiram saem. As sequências que mudam passam antes por um valor
     * provisório, para a troca não colidir com a unique (versão, sequência).
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function syncLines(MeasurementPlanVersion $draft, array $lines): void
    {
        $normalized = $this->normalizeLines($lines);
        // Leitura comum: a versão já está travada e toda escrita de linha passa
        // pela Operation. Num rascunho sem linhas, o FOR UPDATE travaria o fim
        // do índice, compartilhado com outros planos, e os INSERTs fechariam um
        // deadlock entre operações diferentes.
        $existing = $draft->lines()->orderBy('id')->get()->keyBy('id');
        $keptIds = [];
        // A linha da versão anterior tirada do rascunho e incluída de novo --
        // mesma sequência, mesmo mês -- volta com a linhagem dela: é a mesma
        // medição prevista, e a competência passada continua a dela.
        $baseLines = $draft->previous_version_id === null ? new Collection : MeasurementPlanLine::query()
            ->where('plan_version_id', $draft->previous_version_id)
            ->whereNotNull('measurement_date')
            ->get(['id', 'lineage_key', 'sequence_number', 'measurement_date'])
            ->keyBy(fn (MeasurementPlanLine $line): string => $line->sequence_number.'|'.$line->measurement_date->format('Y-m'));

        foreach ($normalized as $index => $line) {
            if ($line['id'] === null) {
                continue;
            }

            if (! $existing->has($line['id']) || in_array($line['id'], $keptIds, true)) {
                throw ValidationException::withMessages([
                    "lines.{$index}" => 'A medição prevista não pertence a este rascunho. Recarregue a página.',
                ]);
            }

            $keptIds[] = $line['id'];
        }

        foreach ($existing as $id => $line) {
            if (! in_array((int) $id, $keptIds, true)) {
                $line->delete();
            }
        }

        $lineagesInDraft = $existing->only($keptIds)->pluck('lineage_key')->flip();

        foreach ($normalized as $line) {
            $model = $line['id'] === null ? null : $existing->get($line['id']);

            if ($model instanceof MeasurementPlanLine && (int) $model->sequence_number !== $line['sequence_number']) {
                $model->forceFill(['sequence_number' => 1_000_000 + (int) $model->getKey()])->save();
            }
        }

        foreach ($normalized as $line) {
            $attributes = [
                'sequence_number' => $line['sequence_number'],
                'planned_monthly_percent' => $line['planned_monthly_percent'],
                'planned_cumulative_percent' => $line['planned_cumulative_percent'],
                'measurement_date' => $line['measurement_date'],
            ];

            if ($line['id'] === null) {
                $new = $draft->lines()->make($attributes);
                $base = $line['measurement_date'] === null ? null : $baseLines->get($line['sequence_number'].'|'.substr((string) $line['measurement_date'], 0, 7));

                if ($base instanceof MeasurementPlanLine && ! $lineagesInDraft->has($base->lineage_key)) {
                    $new->forceFill(['lineage_key' => $base->lineage_key]);
                    $lineagesInDraft->put($base->lineage_key, true);
                }

                $new->save();

                continue;
            }

            $existing->get($line['id'])->fill($attributes)->save();
        }
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<int, array{id: int|null, sequence_number: int, planned_monthly_percent: string, planned_cumulative_percent: string, measurement_date: string|null}>
     */
    private function normalizeLines(array $lines): array
    {
        $errors = [];
        $normalized = [];
        $sequences = [];

        foreach (array_values($lines) as $index => $line) {
            $sequence = filter_var($line['sequence_number'] ?? null, FILTER_VALIDATE_INT);

            if ($sequence === false || $sequence < 1 || $sequence >= 1_000_000) {
                $errors["lines.{$index}.sequence_number"] = 'Informe o número da medição prevista (de 1 a 999.999).';
            } elseif (isset($sequences[$sequence])) {
                $errors["lines.{$index}.sequence_number"] = "A medição prevista {$sequence} aparece mais de uma vez no cronograma.";
            } else {
                $sequences[$sequence] = true;
            }

            $monthly = $this->percent($line['planned_monthly_percent'] ?? 0);
            $cumulative = $this->percent($line['planned_cumulative_percent'] ?? 0);

            if ($monthly === null) {
                $errors["lines.{$index}.planned_monthly_percent"] = 'Informe o previsto mensal entre 0% e 100%, com no máximo duas casas decimais.';
            }

            if ($cumulative === null) {
                $errors["lines.{$index}.planned_cumulative_percent"] = 'Informe o previsto acumulado entre 0% e 100%, com no máximo duas casas decimais.';
            }

            try {
                $month = $this->competence($line['measurement_date'] ?? null, "lines.{$index}.measurement_date");
            } catch (ValidationException $exception) {
                $errors = [...$errors, ...array_map(fn (array $messages): string => $messages[0], $exception->errors())];
                $month = null;
            }

            $id = filled($line['id'] ?? null) ? filter_var($line['id'], FILTER_VALIDATE_INT) : null;

            $normalized[$index] = [
                'id' => $id === false ? null : $id,
                'sequence_number' => $sequence === false ? 0 : (int) $sequence,
                'planned_monthly_percent' => $monthly === null ? '0.00' : MeasurementPhysicalProgress::decimal($monthly),
                'planned_cumulative_percent' => $cumulative === null ? '0.00' : MeasurementPhysicalProgress::decimal($cumulative),
                'measurement_date' => $month,
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $normalized;
    }

    /**
     * Tudo o que a ativação exige, conferido sob o lock. Devolve o cronograma
     * que a ativação grava ({@see self::projectSchedule()}).
     *
     * @return array{cumulative: array<int, int>, pending: int, future: int}
     */
    private function assertActivatable(MeasurementPlanSet $planSet, MeasurementPlanVersion $draft, ?MeasurementPlanVersion $active, MeasurementPhysicalProgress $progress, CarbonImmutable $effectiveFrom): array
    {
        $errors = [];
        $isRevision = (int) $draft->version_number > 1;
        $lines = $draft->lines()->orderBy('measurement_date')->orderBy('sequence_number')->orderBy('id')->get();
        $activeFrom = $active?->effective_from === null ? null : CarbonImmutable::parse($active->effective_from->toDateString())->startOfMonth();
        $lastHeld = $this->lastHeldCompetence($planSet, $progress);

        if ($activeFrom !== null && $effectiveFrom->lt($activeFrom)) {
            $errors['effective_from'] = sprintf(
                'A %s vigente vale a partir de %s; uma revisão ativada agora valeria a partir de %s, antes dela.',
                $active->label(),
                $activeFrom->format('m/Y'),
                $effectiveFrom->format('m/Y'),
            );
        } elseif ($lastHeld !== null && $effectiveFrom->lte($lastHeld['month'])) {
            $errors['effective_from'] = sprintf(
                'A competência %s já tem medição de pé (#%d) e não pode ser replanejada. Ativada agora, a revisão valeria a partir de %s; ative-a a partir de 01/%s.',
                $lastHeld['month']->format('m/Y'),
                $lastHeld['measurement_id'],
                $effectiveFrom->format('m/Y'),
                $lastHeld['month']->addMonthNoOverflow()->format('m/Y'),
            );
        }

        if ($isRevision && blank($draft->revision_reason)) {
            $errors['revision_reason'] = 'Justifique a revisão do plano antes de ativá-la.';
        }

        if ($isRevision && $active?->construction_fund_amount !== null && $draft->construction_fund_amount === null) {
            $errors['construction_fund_amount'] = sprintf(
                'Informe o Fundo de Obra da revisão: a %s vigente tem %s, e a revisão não pode deixar o custo previsto em branco.',
                $active->label(),
                'R$ '.IntegerMoney::format((int) $active->constructionFundCents()),
            );
        }

        if ($lines->isEmpty()) {
            $errors['lines'] = 'A versão precisa de ao menos uma medição prevista no cronograma.';
        }

        foreach ($lines as $line) {
            if ($line->measurement_date === null) {
                $errors['lines'] = sprintf('Informe o mês da medição prevista %s.', $this->sequenceLabel($line));

                break;
            }

            if (! $this->isPercentWithinLimit($line->planned_monthly_percent) || ! $this->isPercentWithinLimit($line->planned_cumulative_percent)) {
                $errors['lines'] = sprintf('O previsto da medição prevista %s precisa estar entre 0%% e 100%%.', $this->sequenceLabel($line));

                break;
            }
        }

        if (! $progress->isVerifiable()) {
            $errors['lines'] = sprintf(
                'Não foi possível conferir o avanço físico do plano: a medição #%d tem a Engenharia aprovada sem o avanço registrado.',
                $progress->unverifiedMeasurementIds[0],
            );
        }

        $monthsWithoutThePlan = $this->monthsMeasuredWithoutThePlan($planSet);

        if ($errors === [] && $isRevision && $active instanceof MeasurementPlanVersion) {
            $pastError = $this->pastScheduleError($active, $lines, $effectiveFrom);

            if ($pastError !== null) {
                $errors['lines'] = $pastError;
            }
        }

        $schedule = $this->projectSchedule($lines, $effectiveFrom, $progress, $isRevision, $monthsWithoutThePlan);
        $ceilingError = $errors === [] ? $this->ceilingError($schedule, $progress, $effectiveFrom, $isRevision) : null;

        if ($ceilingError !== null) {
            $errors['lines'] = $ceilingError;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $schedule;
    }

    /**
     * Um teto de 100%, do plano. Na V1 o cronograma inteiro é o que se
     * planeja: avanço inicial mais todos os previstos. Na revisão, o previsto
     * anterior à vigência ainda não medido é cópia fiel da vigente e não muda:
     * a revisão só controla o que planeja dali em diante, e isso não pode
     * passar do que sobra depois dele.
     *
     * @param  array{cumulative: array<int, int>, pending: int, future: int}  $schedule
     */
    private function ceilingError(array $schedule, MeasurementPhysicalProgress $progress, CarbonImmutable $effectiveFrom, bool $isRevision): ?string
    {
        $current = $progress->currentBasisPoints();
        $limit = MeasurementPhysicalProgress::LIMIT_BASIS_POINTS;

        if (! $isRevision) {
            $planned = $schedule['pending'] + $schedule['future'];

            return $current + $planned <= $limit ? null : sprintf(
                'O cronograma prevê %s de avanço físico mensal somado, mas restam %s da obra (avanço inicial %s).',
                MeasurementPhysicalProgress::format($planned),
                MeasurementPhysicalProgress::format(max(0, $limit - $current)),
                MeasurementPhysicalProgress::format($current),
            );
        }

        $available = max(0, $limit - $current - $schedule['pending']);

        return $schedule['future'] <= $available ? null : sprintf(
            'O cronograma a partir de %s prevê %s de avanço físico mensal somado, mas restam %s da obra (avanço atual %s%s). O avanço já executado não muda com a revisão.',
            $effectiveFrom->format('m/Y'),
            MeasurementPhysicalProgress::format($schedule['future']),
            MeasurementPhysicalProgress::format($available),
            MeasurementPhysicalProgress::format($current),
            $schedule['pending'] > 0
                ? sprintf(', mais %s previstos antes de %s e ainda não medidos', MeasurementPhysicalProgress::format($schedule['pending']), $effectiveFrom->format('m/Y'))
                : '',
        );
    }

    /**
     * Uma revisão não reescreve o passado: as medições previstas da versão
     * vigente anteriores à vigência continuam na revisão, iguais -- mesma
     * linhagem, competência, sequência e previsto --, e nenhuma nova entra
     * antes da vigência. É o que mantém a versão vigente como o retrato fiel do
     * que cada competência passada tinha planejado. Como a vigência vem depois
     * de toda competência com medição de pé, a medição prevista que uma medição
     * ocupa está sempre nesse passado preservado.
     *
     * @param  Collection<int, MeasurementPlanLine>  $lines
     */
    private function pastScheduleError(MeasurementPlanVersion $active, Collection $lines, CarbonImmutable $effectiveFrom): ?string
    {
        $limit = $effectiveFrom->toDateString();
        $draftByLineage = $lines->keyBy('lineage_key');
        $activeLines = $active->lines()->orderBy('measurement_date')->orderBy('sequence_number')->get();
        // Só a linhagem que já estava no passado da vigente pode estar antes da
        // vigência: mover para trás uma medição prevista futura abriria de novo
        // uma competência passada -- talvez já medida, por outra linhagem.
        $activePastLineages = $activeLines
            ->filter(fn (MeasurementPlanLine $line): bool => $line->measurement_date !== null && $line->measurement_date->toDateString() < $limit)
            ->pluck('lineage_key')
            ->flip();

        foreach ($activeLines as $base) {
            if ($base->measurement_date === null || $base->measurement_date->toDateString() >= $limit) {
                continue;
            }

            $copy = $draftByLineage->get($base->lineage_key);

            if (! $copy instanceof MeasurementPlanLine || $this->lineChanges($base, $copy) !== []) {
                return sprintf(
                    'A medição prevista %s (%s) é anterior à vigência %s e precisa continuar igual à da %s: a revisão não reescreve competências passadas.%s',
                    $this->sequenceLabel($base),
                    $base->measurement_date->format('m/Y'),
                    $effectiveFrom->format('m/Y'),
                    $active->label(),
                    $copy instanceof MeasurementPlanLine ? '' : ' Se ela saiu do rascunho, inclua-a de novo com a mesma sequência e o mesmo mês.',
                );
            }
        }

        foreach ($lines as $line) {
            if ($line->measurement_date !== null
                && $line->measurement_date->toDateString() < $limit
                && ! $activePastLineages->has($line->lineage_key)) {
                return sprintf(
                    'A medição prevista %s (%s) é anterior à vigência %s: a revisão só acrescenta medições previstas a partir da vigência.',
                    $this->sequenceLabel($line),
                    $line->measurement_date->format('m/Y'),
                    $effectiveFrom->format('m/Y'),
                );
            }
        }

        return null;
    }

    /**
     * O acumulado previsto que a ativação grava e o que entra no teto de 100%.
     *
     * O mensal é incremental e o acumulado é meta da obra inteira. A partir do
     * avanço físico atual somam-se, em ordem de competência e sequência, o
     * previsto das medições previstas anteriores à vigência que ainda não
     * foram medidas -- continuam a medir, atrasadas -- e o da vigência em
     * diante. A medição prevista coberta pelo avanço inicial e a já medida
     * pela Engenharia não entram: o atual já as contém. Derivar o acumulado do
     * atual impede uma versão que "recomeça do zero".
     *
     * Na revisão as linhas anteriores à vigência são cópia fiel da vigente e
     * guardam o acumulado que tinham; na V1, que nada mediu ainda, todas
     * recebem o acumulado derivado.
     *
     * A linha de uma competência que a operação já mediu sem este plano
     * ({@see self::monthsMeasuredWithoutThePlan()}) não entra -- nem como
     * pendente, nem no previsto da vigência em diante: enquanto aquela medição
     * estiver de pé, não há como medi-la. Recusada a medição, ela volta a
     * contar, e a competência se reenvia com os dois planos.
     *
     * @param  Collection<int, MeasurementPlanLine>  $lines
     * @param  array<string, int>  $monthsWithoutThePlan  competência ('Y-m') => medição de pé sem este plano
     * @return array{cumulative: array<int, int>, pending: int, future: int} acumulado por id de linha; previsto pendente antes da vigência e previsto da vigência em diante, em basis points
     */
    private function projectSchedule(Collection $lines, CarbonImmutable $effectiveFrom, MeasurementPhysicalProgress $progress, bool $isRevision, array $monthsWithoutThePlan = []): array
    {
        $limit = $effectiveFrom->toDateString();
        $running = $progress->currentBasisPoints();
        $cumulative = [];
        $pending = 0;
        $future = 0;

        $ordered = $lines
            ->filter(fn (MeasurementPlanLine $line): bool => $line->measurement_date !== null)
            ->sortBy(fn (MeasurementPlanLine $line): string => $line->measurement_date->toDateString().'-'.str_pad((string) $line->sequence_number, 10, '0', STR_PAD_LEFT).'-'.str_pad((string) $line->getKey(), 12, '0', STR_PAD_LEFT));

        foreach ($ordered as $line) {
            // A competência que o avanço inicial cobre não se mede (a
            // Engenharia recusa): o previsto dela já está no inicial, antes ou
            // depois da vigência. A que a operação mediu sem este plano não tem,
            // enquanto aquela medição estiver de pé, como receber a dele.
            if ($progress->initialProgressCovers($line->measurement_date)
                || array_key_exists($line->measurement_date->format('Y-m'), $monthsWithoutThePlan)) {
                continue;
            }

            $monthly = max(0, (int) MeasurementPhysicalProgress::basisPoints($line->planned_monthly_percent));

            if ($line->measurement_date->toDateString() >= $limit) {
                $running += $monthly;
                $future += $monthly;
                // A meta acumulada é da obra inteira e para no teto: na revisão,
                // o previsto passado ainda por medir (imutável) pode sozinho
                // passar do que resta, e a obra não chega a mais de 100%.
                $cumulative[(int) $line->getKey()] = min($running, MeasurementPhysicalProgress::LIMIT_BASIS_POINTS);

                continue;
            }

            if ($progress->contributionsForLineage((string) $line->lineage_key) !== []) {
                continue;
            }

            $running += $monthly;
            $pending += $monthly;

            if (! $isRevision) {
                $cumulative[(int) $line->getKey()] = min($running, MeasurementPhysicalProgress::LIMIT_BASIS_POINTS);
            }
        }

        return ['cumulative' => $cumulative, 'pending' => $pending, 'future' => $future];
    }

    /**
     * Competências em que a medição prevista deste plano não tem, hoje, como
     * ser medida: a operação já tem medição de pé nelas (aberta, finalizada,
     * com Engenharia vigente ou com pagamento) enviada antes de este plano
     * valer -- que por isso não o cobre -- e outro plano em vigor não tem
     * medição prevista livre na competência. Uma segunda medição da
     * competência teria de cobrir esse plano, e a linha dele ali está ocupada.
     *
     * A medição enviada com o plano já em vigor e sem o arquivo dele não entra:
     * ela é que está errada, e a Engenharia a recusa. Recusada a medição de pé,
     * a competência deixa de constar e volta a ser medida com os dois planos.
     *
     * @return array<string, int> competência ('Y-m') => a medição de pé
     */
    public function monthsMeasuredWithoutThePlan(MeasurementPlanSet $planSet): array
    {
        $firstActivation = MeasurementPlanVersion::query()
            ->where('plan_set_id', $planSet->getKey())
            ->whereNotNull('activated_at')
            ->orderBy('activated_at')
            ->orderBy('id')
            ->first(['id', 'last_measurement_id_at_activation']);

        // Plano em vigor desde antes de qualquer medição da operação (ou o
        // legado das versões): toda medição precisa cobri-lo.
        if ($firstActivation instanceof MeasurementPlanVersion && $firstActivation->last_measurement_id_at_activation === null) {
            return [];
        }

        $standing = Measurement::query()
            ->where('operation_id', $planSet->operation_id)
            ->whereNotNull('reference_month')
            ->when($firstActivation instanceof MeasurementPlanVersion, fn (Builder $before): Builder => $before
                ->where('id', '<=', (int) $firstActivation->last_measurement_id_at_activation))
            ->where(fn (Builder $holding): Builder => $holding
                ->whereIn('status', [...Measurement::OPEN_STATUSES, 'finalized'])
                ->orWhereHas('reviews', fn (Builder $reviews): Builder => $reviews->where('stage', 1)->where('status', 'approved'))
                ->orWhereHas('payments'))
            ->whereDoesntHave('assets', fn (Builder $assets): Builder => $assets->where('plan_set_id', $planSet->getKey()))
            ->orderBy('id')
            ->get(['id', 'reference_month'])
            ->mapWithKeys(fn (Measurement $measurement): array => [$measurement->reference_month->format('Y-m') => (int) $measurement->getKey()]);

        if ($standing->isEmpty()) {
            return [];
        }

        // A competência em que este plano já tem medição de pé com arquivo
        // dele não fica sem medição: aquela o mede.
        $measuredWithThePlan = Measurement::query()
            ->where('operation_id', $planSet->operation_id)
            ->whereNotNull('reference_month')
            ->where(fn (Builder $holding): Builder => $holding
                ->whereIn('status', [...Measurement::OPEN_STATUSES, 'finalized'])
                ->orWhereHas('reviews', fn (Builder $reviews): Builder => $reviews->where('stage', 1)->where('status', 'approved'))
                ->orWhereHas('payments'))
            ->whereHas('assets', fn (Builder $assets): Builder => $assets->where('plan_set_id', $planSet->getKey()))
            ->get(['id', 'reference_month'])
            ->map(fn (Measurement $measurement): string => $measurement->reference_month->format('Y-m'))
            ->flip();

        $otherPlansInForce = MeasurementPlanSet::query()
            ->where('operation_id', $planSet->operation_id)
            ->whereKeyNot($planSet->getKey())
            ->whereHas('activeVersion')
            ->pluck('id');

        // Outra medição da competência teria de cobrir o plano em vigor que
        // prevê medição nela -- e só fica sem saída se todas as dele ali já
        // estiverem ocupadas. O plano sem medição prevista no mês não é
        // exigido nele (MeasurementEngineeringService) e não impede nada.
        return $standing
            ->reject(fn (int $measurementId, string $month): bool => $measuredWithThePlan->has($month))
            ->filter(function (int $measurementId, string $month) use ($otherPlansInForce): bool {
                $competence = CarbonImmutable::parse($month.'-01');
                $inMonth = fn (mixed $otherPlanSetId) => MeasurementPlanLine::query()
                    ->ofActiveVersions()
                    ->where('plan_set_id', $otherPlanSetId)
                    ->whereBetween('measurement_date', [$competence->toDateString(), $competence->endOfMonth()->toDateString()]);

                return $otherPlansInForce->contains(fn (mixed $otherPlanSetId): bool => $inMonth($otherPlanSetId)->exists()
                    && ! $inMonth($otherPlanSetId)->availableForMeasurement()->exists());
            })
            ->all();
    }

    /**
     * A competência mais recente do plano que já tem medição de pé: a da
     * medição prevista que cada medição de pé de fato ocupa -- aberta,
     * finalizada, com Engenharia vigente ou com pagamento, pelo arquivo dela --
     * e a de cada avanço aprovado. As cópias da mesma linhagem em outras
     * versões (um rascunho cancelado que a mudou de mês, a versão anterior) não
     * contam: a medição está na linha do próprio arquivo.
     *
     * @return array{month: CarbonImmutable, measurement_id: int}|null
     */
    private function lastHeldCompetence(MeasurementPlanSet $planSet, MeasurementPhysicalProgress $progress): ?array
    {
        $held = DB::table('measurement_assets')
            ->join('measurements as holding_measurements', 'holding_measurements.id', '=', 'measurement_assets.measurement_id')
            ->join('measurement_plan_lines as held_lines', 'held_lines.id', '=', 'measurement_assets.plan_line_id')
            ->where('measurement_assets.plan_set_id', $planSet->getKey())
            ->whereNotNull('held_lines.measurement_date')
            ->where(fn (QueryBuilder $holding): QueryBuilder => $holding
                ->whereNotNull('measurement_assets.line_claim_key')
                ->orWhereIn('holding_measurements.status', [...Measurement::OPEN_STATUSES, 'finalized'])
                ->orWhereExists(fn (QueryBuilder $reviews): QueryBuilder => $reviews
                    ->from('measurement_reviews')
                    ->whereColumn('measurement_reviews.measurement_id', 'holding_measurements.id')
                    ->where('measurement_reviews.stage', 1)
                    ->where('measurement_reviews.status', 'approved'))
                ->orWhereExists(fn (QueryBuilder $payments): QueryBuilder => $payments
                    ->from('measurement_payments')
                    ->whereColumn('measurement_payments.measurement_id', 'holding_measurements.id')))
            ->orderByDesc('held_lines.measurement_date')
            ->orderByDesc('measurement_assets.measurement_id')
            ->first(['held_lines.measurement_date as held_month', 'measurement_assets.measurement_id as holder_id']);
        $last = $held === null ? null : [
            'month' => CarbonImmutable::parse((string) $held->held_month)->startOfMonth(),
            'measurement_id' => (int) $held->holder_id,
        ];

        foreach ($progress->contributions as $contribution) {
            if ($contribution->measurementDate === null) {
                continue;
            }

            $month = $contribution->measurementDate->startOfMonth();

            if ($last === null || $month->gt($last['month'])) {
                $last = ['month' => $month, 'measurement_id' => $contribution->measurementId];
            }
        }

        return $last;
    }

    private function isPercentWithinLimit(mixed $value): bool
    {
        $basisPoints = MeasurementPhysicalProgress::basisPoints($value);

        return $basisPoints !== null && $basisPoints >= 0 && $basisPoints <= MeasurementPhysicalProgress::LIMIT_BASIS_POINTS;
    }

    /**
     * Diferenças de previsto entre duas linhas da mesma linhagem. A
     * competência é comparada pelo mês: o dia não é dado do cronograma.
     *
     * @return list<string>
     */
    private function lineChanges(MeasurementPlanLine $before, MeasurementPlanLine $after): array
    {
        $changes = [];

        if ((int) $before->sequence_number !== (int) $after->sequence_number) {
            $changes[] = 'sequence_number';
        }

        if ($before->measurement_date?->format('Y-m') !== $after->measurement_date?->format('Y-m')) {
            $changes[] = 'measurement_date';
        }

        if (MeasurementPhysicalProgress::basisPoints($before->planned_monthly_percent) !== MeasurementPhysicalProgress::basisPoints($after->planned_monthly_percent)) {
            $changes[] = 'planned_monthly_percent';
        }

        if (MeasurementPhysicalProgress::basisPoints($before->planned_cumulative_percent) !== MeasurementPhysicalProgress::basisPoints($after->planned_cumulative_percent)) {
            $changes[] = 'planned_cumulative_percent';
        }

        return $changes;
    }

    /**
     * @return array{sequence_number: int, measurement_date: string|null, planned_monthly_percent: string, planned_cumulative_percent: string}
     */
    private function lineSummary(MeasurementPlanLine $line): array
    {
        return [
            'sequence_number' => (int) $line->sequence_number,
            'measurement_date' => $line->measurement_date?->toDateString(),
            'planned_monthly_percent' => MeasurementPhysicalProgress::decimal((int) MeasurementPhysicalProgress::basisPoints($line->planned_monthly_percent)),
            'planned_cumulative_percent' => MeasurementPhysicalProgress::decimal((int) MeasurementPhysicalProgress::basisPoints($line->planned_cumulative_percent)),
        ];
    }

    /**
     * Término previsto: o último dia do mês da última medição prevista com
     * avanço planejado (sem nenhuma, o da última medição prevista).
     *
     * @param  Collection<int, MeasurementPlanLine>  $lines
     */
    private function completion(Collection $lines): ?CarbonImmutable
    {
        $dated = $lines->filter(fn (MeasurementPlanLine $line): bool => $line->measurement_date !== null);
        $planned = $dated->filter(fn (MeasurementPlanLine $line): bool => (int) MeasurementPhysicalProgress::basisPoints($line->planned_monthly_percent) > 0);
        $last = ($planned->isNotEmpty() ? $planned : $dated)
            ->map(fn (MeasurementPlanLine $line): string => $line->measurement_date->toDateString())
            ->max();

        return $last === null ? null : CarbonImmutable::parse($last)->endOfMonth()->startOfDay();
    }

    /**
     * @param  Collection<int, MeasurementPlanLine>  $lines
     */
    private function finalCumulative(Collection $lines): ?int
    {
        $last = $lines
            ->filter(fn (MeasurementPlanLine $line): bool => $line->measurement_date !== null)
            ->sortBy(fn (MeasurementPlanLine $line): string => $line->measurement_date->toDateString().'-'.str_pad((string) $line->sequence_number, 10, '0', STR_PAD_LEFT))
            ->last();

        return $last === null ? null : MeasurementPhysicalProgress::basisPoints($last->planned_cumulative_percent);
    }

    private function sequenceLabel(MeasurementPlanLine $line): string
    {
        return str_pad((string) $line->sequence_number, 2, '0', STR_PAD_LEFT);
    }

    /**
     * Evento de ciclo de vida da versão na trilha protegida `measurements`,
     * gravado com o conteúdo completo (linhas já gravadas) e com quem decidiu.
     * O buffer do activitylog está desligado: a linha da trilha volta junto se
     * a transação for desfeita.
     *
     * @param  array<string, mixed>  $extra
     */
    private function audit(MeasurementPlanVersion $version, string $event, User $actor, array $extra = []): void
    {
        $planSet = $version->planSet()->first();
        $previous = $version->previous_version_id === null ? null : MeasurementPlanVersion::query()->find($version->previous_version_id);
        $fund = $version->constructionFundCents();
        $previousFund = $previous?->constructionFundCents();
        $variation = $fund !== null && $previousFund !== null ? $fund - $previousFund : null;
        $share = $variation === null || $previousFund === null ? null : IntegerMoney::shareInBasisPoints($variation, $previousFund);

        activity('measurements')
            ->performedOn($version)
            ->causedBy($actor)
            ->event($event)
            ->withProperties([
                'operation_id' => (int) $version->operation_id,
                'plan_set_id' => (int) $version->plan_set_id,
                'construction_id' => $planSet?->construction_id === null ? null : (int) $planSet->construction_id,
                'plan_version_id' => (int) $version->getKey(),
                'version_number' => (int) $version->version_number,
                'status' => $version->status->value,
                'previous_version_id' => $previous?->getKey(),
                'previous_version_number' => $previous?->version_number,
                'effective_from' => $version->effective_from?->toDateString(),
                'revision_category' => $version->revision_category?->value,
                'revision_reason' => $version->revision_reason,
                'cancellation_reason' => $version->cancellation_reason,
                'construction_fund_amount' => $fund === null ? null : IntegerMoney::decimalString($fund),
                'previous_construction_fund_amount' => $previousFund === null ? null : IntegerMoney::decimalString($previousFund),
                'construction_fund_variation_amount' => $variation === null ? null : IntegerMoney::decimalString($variation),
                'construction_fund_variation_percent' => $share === null ? null : IntegerMoney::decimalString($share),
                'line_count' => $version->lines()->count(),
                'revision' => (int) $version->revision,
                'actor_user_id' => (int) $actor->getKey(),
                ...$extra,
            ])
            ->log($event);
    }

    /**
     * Trava a Operation, o plano e as versões do plano e devolve o rascunho
     * pedido, conferindo que ele ainda é rascunho e é o que a pessoa viu.
     */
    private function lockDraft(MeasurementPlanVersion $version, User $actor, int $expectedRevision): MeasurementPlanVersion
    {
        $operation = $this->lockOperation((int) $version->operation_id);
        $this->authorize($actor, $operation);
        $this->assertOperationAcceptsPlanChanges($operation);
        $planSet = $this->lockPlanSet((int) $version->plan_set_id, $operation);

        return $this->draftAmong($this->lockVersions($planSet), $version, $expectedRevision);
    }

    /**
     * @param  Collection<int, MeasurementPlanVersion>  $versions
     */
    private function draftAmong(Collection $versions, MeasurementPlanVersion $version, int $expectedRevision): MeasurementPlanVersion
    {
        $locked = $versions->first(fn (MeasurementPlanVersion $candidate): bool => (int) $candidate->getKey() === (int) $version->getKey());

        if (! $locked instanceof MeasurementPlanVersion) {
            throw new MeasurementWorkflowException('A versão do plano não foi encontrada. Recarregue a página.', [
                'plan_version_id' => $version->getKey(),
            ]);
        }

        if (! $locked->isDraft()) {
            throw new MeasurementWorkflowException(sprintf(self::NOT_A_DRAFT_MESSAGE, $locked->label(), mb_strtolower($locked->status->label())), [
                'plan_version_id' => $locked->getKey(),
                'status' => $locked->status->value,
            ]);
        }

        if ((int) $locked->revision !== $expectedRevision) {
            throw new MeasurementWorkflowException(sprintf(self::STALE_DRAFT_MESSAGE, $locked->label()), [
                'plan_version_id' => $locked->getKey(),
                'expected_revision' => $expectedRevision,
                'current_revision' => (int) $locked->revision,
            ]);
        }

        return $locked;
    }

    /**
     * Primeira instrução da transação: o `operation_id` vem da memória, sem
     * leitura comum antes do lock. A autorização é conferida contra a linha
     * travada.
     */
    private function lockOperation(int $operationId): Operation
    {
        if (DB::transactionLevel() < 1) {
            throw new MeasurementWorkflowException('A escrita do plano de medição deve ocorrer dentro da transação que trava a operação.');
        }

        $operation = Operation::query()->whereKey($operationId)->lockForUpdate()->first();

        if (! $operation instanceof Operation) {
            throw new MeasurementWorkflowException('A operação do plano de medição não foi encontrada.', [
                'operation_id' => $operationId,
            ]);
        }

        return $operation;
    }

    private function lockPlanSet(int $planSetId, Operation $operation): MeasurementPlanSet
    {
        $planSet = MeasurementPlanSet::query()
            ->where('operation_id', $operation->getKey())
            ->whereKey($planSetId)
            ->lockForUpdate()
            ->first();

        if (! $planSet instanceof MeasurementPlanSet) {
            throw new MeasurementWorkflowException('O plano de medição não foi encontrado nesta operação. Recarregue a página.', [
                'plan_set_id' => $planSetId,
                'operation_id' => $operation->getKey(),
            ]);
        }

        return $planSet;
    }

    /**
     * @return Collection<int, MeasurementPlanVersion>
     */
    private function lockVersions(MeasurementPlanSet $planSet): Collection
    {
        return MeasurementPlanVersion::query()
            ->where('plan_set_id', $planSet->getKey())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @throws AuthorizationException
     */
    private function authorize(User $actor, Operation $operation): void
    {
        if (! Gate::forUser($actor)->allows('update', $operation)) {
            throw new AuthorizationException('Você não pode alterar os planos de medição desta operação.');
        }
    }

    /**
     * Operação concluída ou cancelada não replaneja
     * ({@see OperationStatus::allowsPlanChanges()}): plano novo, revisão,
     * edição do rascunho e ativação esperam a reabertura. Cancelar um rascunho
     * continua possível -- é arrumação, não plano novo.
     */
    private function assertOperationAcceptsPlanChanges(Operation $operation): void
    {
        if (! $operation->status->allowsPlanChanges()) {
            throw new MeasurementWorkflowException(sprintf(self::TERMINAL_OPERATION_MESSAGE, mb_strtolower($operation->status->label())), [
                'operation_id' => $operation->getKey(),
                'status' => $operation->status->value,
            ]);
        }
    }

    /**
     * Competência (primeiro dia do mês) a partir de 'Y-m', 'Y-m-d' ou data;
     * vazio é ausência.
     *
     * @throws ValidationException
     */
    private function competence(mixed $value, string $field): ?string
    {
        if (blank($value)) {
            return null;
        }

        try {
            $date = $value instanceof \DateTimeInterface
                ? CarbonImmutable::parse($value->format('Y-m-d'))
                : CarbonImmutable::parse(preg_match('/^\d{4}-\d{2}$/', (string) $value) === 1 ? $value.'-01' : (string) $value);
        } catch (Throwable) {
            throw ValidationException::withMessages([$field => 'Informe um mês válido (mm/aaaa).']);
        }

        return $date->startOfMonth()->toDateString();
    }

    /**
     * Valor monetário exato, como a coluna `decimal(18,2)` o guarda; vazio é
     * ausência, negativo e formato inválido são recusados.
     *
     * @throws ValidationException
     */
    private function money(mixed $value, string $field): ?string
    {
        if (blank($value)) {
            return null;
        }

        $cents = IntegerMoney::cents(is_float($value) ? sprintf('%.2F', $value) : $value);

        if ($cents === null || $cents < 0) {
            throw ValidationException::withMessages([$field => 'Informe o Fundo de Obra como um valor em reais, zero ou positivo.']);
        }

        return IntegerMoney::decimalString($cents);
    }

    private function percent(mixed $value): ?int
    {
        $basisPoints = MeasurementPhysicalProgress::basisPoints($value === null || $value === '' ? 0 : $value);

        return $basisPoints === null || $basisPoints < 0 || $basisPoints > MeasurementPhysicalProgress::LIMIT_BASIS_POINTS
            ? null
            : $basisPoints;
    }

    /**
     * Categoria informada; valor desconhecido é recusado, não trocado por
     * outro.
     *
     * @throws ValidationException
     */
    private function category(mixed $value): ?MeasurementPlanRevisionCategory
    {
        if (blank($value)) {
            return null;
        }

        if ($value instanceof MeasurementPlanRevisionCategory) {
            return $value;
        }

        return MeasurementPlanRevisionCategory::tryFrom((string) $value)
            ?? throw ValidationException::withMessages(['revision_category' => 'Escolha uma categoria de revisão válida.']);
    }

    private function text(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
