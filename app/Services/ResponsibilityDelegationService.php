<?php

namespace App\Services;

use App\Enums\DelegationIneffectivenessReason;
use App\Enums\MeasurementResponsibility;
use App\Models\Operation;
use App\Models\ResponsibilityDelegation;
use App\Models\User;
use App\Notifications\DelegationCreatedNotification;
use App\Notifications\DelegationRevokedNotification;
use App\Support\Delegations\DelegationAuthorityContext;
use App\Support\Delegations\DelegationEffectiveness;
use App\Support\Delegations\DelegationWindow;
use App\Support\Users\UserIdentityMap;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ResponsibilityDelegationService
{
    /**
     * Teto de estados (usuário + contexto + janela) percorridos pela detecção de
     * ciclo antes de recusar por não conseguir validar o grafo com segurança.
     */
    private const MAX_CYCLE_PATH_STATES = 1000;

    /**
     * Efetividade já resolvida nesta instância, por delegação.
     *
     * @var array<int, DelegationEffectiveness>
     */
    private array $resolvedEffectiveness = [];

    /**
     * @param  array{delegator_user_id: int, delegate_user_id: int, scope_type: string, scope_operation_id?: ?int, scope_stage?: ?int, scope_responsibility?: ?string, starts_at: string|\DateTimeInterface, ends_at: string|\DateTimeInterface, reason: string}  $data
     */
    public function createDelegation(array $data, User $actor): ResponsibilityDelegation
    {
        $data = $this->normalize($data);
        $this->assertCanCreate($actor, (int) $data['delegator_user_id']);

        $delegation = DB::transaction(function () use ($data, $actor): ResponsibilityDelegation {
            $users = User::query()
                ->whereKey([$data['delegator_user_id'], $data['delegate_user_id']])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (User $user): int => (int) $user->getKey());

            $delegator = $users->get((int) $data['delegator_user_id']);
            $delegate = $users->get((int) $data['delegate_user_id']);

            if (! $delegator instanceof User) {
                throw ValidationException::withMessages(['delegator_user_id' => 'Delegante inválido.']);
            }

            if (! $delegate instanceof User) {
                throw ValidationException::withMessages(['delegate_user_id' => 'Delegado inválido.']);
            }

            $this->validateBusinessRules($delegator, $delegate, $data);
            $this->assertDelegatorHasAuthority($delegator, $data);
            $this->assertNoOverlapping($data);
            $this->assertNoCycle($data);

            $delegation = ResponsibilityDelegation::query()->create([
                'delegator_user_id' => $delegator->getKey(),
                'delegate_user_id' => $delegate->getKey(),
                'scope_type' => $data['scope_type'],
                'scope_operation_id' => $data['scope_operation_id'],
                'scope_stage' => $data['scope_stage'],
                'scope_responsibility' => $data['scope_responsibility'],
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'],
                'reason' => $data['reason'],
                'created_by' => $actor->getKey(),
            ]);

            activity('delegations')
                ->performedOn($delegation)
                ->causedBy($actor)
                ->withProperties($this->auditProperties($delegation))
                ->log('delegation_created');

            return $delegation->load(['delegator', 'delegate', 'scopeOperation']);
        }, 3);

        try {
            $delegation->delegate->notify((new DelegationCreatedNotification($delegation))->afterCommit());
        } catch (\Throwable $exception) {
            report($exception);
        }

        return $delegation;
    }

    public function revokeDelegation(
        ResponsibilityDelegation $delegation,
        User $actor,
        string $revocationReason,
    ): void {
        $revocationReason = Str::squish($revocationReason);

        if ($revocationReason === '') {
            throw ValidationException::withMessages(['revocation_reason' => 'Informe o motivo da revogação.']);
        }

        if (Str::length($revocationReason) > 1000) {
            throw ValidationException::withMessages(['revocation_reason' => 'O motivo da revogação deve ter no máximo 1000 caracteres.']);
        }

        $revoked = DB::transaction(function () use ($delegation, $actor, $revocationReason): ResponsibilityDelegation {
            $locked = ResponsibilityDelegation::query()
                ->with(['delegator', 'delegate', 'scopeOperation'])
                ->whereKey($delegation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertCanRevoke($actor, $locked);

            if ($locked->isRevoked()) {
                throw ValidationException::withMessages(['delegation' => 'Delegação já revogada.']);
            }

            $locked->forceFill([
                'revoked_at' => now(),
                'revoked_by' => $actor->getKey(),
                'revocation_reason' => $revocationReason,
            ])->save();

            activity('delegations')
                ->performedOn($locked)
                ->causedBy($actor)
                ->withProperties(array_merge($this->auditProperties($locked), [
                    'revoked_at' => $locked->revoked_at?->toDateTimeString(),
                    'revoked_by' => $actor->getKey(),
                    'revocation_reason' => $revocationReason,
                ]))
                ->log('delegation_revoked');

            return $locked;
        }, 3);

        try {
            $revoked->delegate->notify((new DelegationRevokedNotification($revoked))->afterCommit());

            if ((int) $revoked->delegator_user_id !== (int) $actor->getKey()) {
                $revoked->delegator->notify((new DelegationRevokedNotification($revoked))->afterCommit());
            }
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    public function activeDelegationFor(
        User $delegate,
        Operation $operation,
        MeasurementResponsibility $responsibility,
    ): ?ResponsibilityDelegation {
        $responsibleUserId = $operation->responsibleUserIdFor($responsibility);

        if ($responsibleUserId === null || $responsibleUserId === (int) $delegate->getKey()) {
            return null;
        }

        // Nenhuma relação é pré-carregada aqui. Quem chama já tem o delegado em
        // mãos; as roles/permissions dos dois são condição do próprio SQL de
        // efetividade (ver effectiveDelegationsForResponsibilityQuery) e nenhum
        // consumidor as lê; e o delegante só interessa a quem monta payload, que
        // o carrega sob demanda para as poucas linhas exibidas. Pré-carregar
        // custava seis consultas por chamada, em toda chamada.
        return $this->effectiveDelegationsForResponsibilityQuery($responsibility)
            ->where('delegate_user_id', $delegate->getKey())
            ->where('delegator_user_id', $responsibleUserId)
            ->latest('id')
            ->get()
            ->first(fn (ResponsibilityDelegation $delegation): bool => $delegation->covers($operation, $responsibility));
    }

    /**
     * As delegações efetivas desta responsabilidade nesta operação, com
     * `delegate` e `delegator` sempre carregados.
     *
     * O delegado é notificado, o delegante aparece na mensagem. As
     * roles/permissions de ambos já foram exigidas pelo SQL de efetividade.
     *
     * Quem chama em laço pode passar um {@see UserIdentityMap} da própria
     * execução: os dois principais saem dele em vez de um eager-load por
     * chamada, que reconsultava os mesmos usuários a cada operação visitada. A
     * consulta de delegações em si continua sendo feita sempre -- ela depende da
     * operação e da responsabilidade, não só de quem é o usuário.
     *
     * @return Collection<int, ResponsibilityDelegation>
     */
    public function activeDelegatesForResponsibility(
        Operation $operation,
        MeasurementResponsibility $responsibility,
        ?UserIdentityMap $users = null,
    ): Collection {
        $responsibleUserId = $operation->responsibleUserIdFor($responsibility);

        if ($responsibleUserId === null) {
            return new Collection;
        }

        $query = $this->effectiveDelegationsForResponsibilityQuery($responsibility)
            ->where('delegator_user_id', $responsibleUserId)
            ->orderBy('id');

        if (! $users instanceof UserIdentityMap) {
            $query->with(['delegate', 'delegator']);
        }

        $delegations = $query
            ->get()
            ->filter(fn (ResponsibilityDelegation $delegation): bool => $delegation->covers($operation, $responsibility))
            ->values();

        if ($users instanceof UserIdentityMap) {
            $this->attachPrincipalsFrom($delegations, $users);
        }

        return $delegations;
    }

    /**
     * @param  Collection<int, ResponsibilityDelegation>  $delegations
     */
    private function attachPrincipalsFrom(Collection $delegations, UserIdentityMap $users): void
    {
        $users->load($delegations
            ->pluck('delegate_user_id')
            ->merge($delegations->pluck('delegator_user_id'))
            ->all());

        foreach ($delegations as $delegation) {
            $delegation->setRelation('delegate', $users->get((int) $delegation->delegate_user_id));
            $delegation->setRelation('delegator', $users->get((int) $delegation->delegator_user_id));
        }
    }

    public function hasAnyActiveDelegatedResponsibility(User $delegate, Operation $operation): bool
    {
        $permittedResponsibilities = $this->permittedResponsibilitiesFor($delegate);

        if ($permittedResponsibilities === []) {
            return false;
        }

        $operationTable = (new Operation)->getTable();

        return Operation::query()
            ->whereKey($operation->getKey())
            ->where(fn (Builder $delegated): Builder => $this->applyEffectiveDelegatedVisibility(
                $delegated,
                $delegate,
                $operationTable,
                $permittedResponsibilities,
            ))
            ->exists();
    }

    /** @return Collection<int, ResponsibilityDelegation> */
    public function activeDelegationsFor(User $user): Collection
    {
        return ResponsibilityDelegation::query()
            ->active()
            ->with([
                'delegate.roles.permissions',
                'delegate.permissions',
                'delegator.roles.permissions',
                'delegator.permissions',
                'scopeOperation:id,code,title',
            ])
            ->where('delegate_user_id', $user->getKey())
            ->orderBy('ends_at')
            ->get()
            ->filter(fn (ResponsibilityDelegation $delegation): bool => $this->effectiveStatus($delegation) === 'active')
            ->values();
    }

    public function effectiveStatus(ResponsibilityDelegation $delegation): string
    {
        return $this->effectiveness($delegation)->status;
    }

    /**
     * O status efetivo e, quando ele é `ineffective`, a condição que falhou.
     *
     * A varredura é a mesma de sempre -- para cada responsabilidade que o escopo
     * pode cobrir, a delegação é efetiva se os dois principais passam pelo SQL de
     * efetividade e o delegante ainda detém a responsabilidade em alguma operação
     * do escopo. A diferença é que agora, quando nenhuma responsabilidade passa, a
     * primeira que o escopo cobria é reexaminada condição a condição, com os
     * mesmos predicados que o SQL usou, para dizer qual delas reprovou.
     *
     * O diagnóstico só roda no caminho já perdido, e só depois de a varredura
     * inteira falhar: delegação efetiva não paga nada por ele. O resultado é
     * memorizado por instância porque tabela e tooltip perguntam a mesma coisa
     * duas vezes na mesma renderização.
     */
    public function effectiveness(ResponsibilityDelegation $delegation): DelegationEffectiveness
    {
        $key = (int) $delegation->getKey();

        if (isset($this->resolvedEffectiveness[$key])) {
            return $this->resolvedEffectiveness[$key];
        }

        return $this->resolvedEffectiveness[$key] = $this->resolveEffectiveness($delegation);
    }

    private function resolveEffectiveness(ResponsibilityDelegation $delegation): DelegationEffectiveness
    {
        if ($delegation->status !== 'active') {
            return DelegationEffectiveness::of($delegation->status);
        }

        $covered = [];

        foreach (MeasurementResponsibility::cases() as $responsibility) {
            if (! $this->delegationCanCoverResponsibility($delegation, $responsibility)) {
                continue;
            }

            $covered[] = $responsibility;

            $isEffectiveForPrincipals = $this->effectiveDelegationsForResponsibilityQuery($responsibility)
                ->whereKey($delegation->getKey())
                ->exists();

            if (! $isEffectiveForPrincipals) {
                continue;
            }

            if ($this->delegatorHoldsResponsibility($delegation, $responsibility)) {
                return DelegationEffectiveness::of('active');
            }
        }

        return DelegationEffectiveness::ineffective($this->ineffectivenessReason($delegation, $covered));
    }

    private function delegatorHoldsResponsibility(
        ResponsibilityDelegation $delegation,
        MeasurementResponsibility $responsibility,
    ): bool {
        $operations = Operation::query()
            ->where($responsibility->operationColumn(), $delegation->delegator_user_id);

        if ($delegation->scope_operation_id !== null) {
            $operations->whereKey($delegation->scope_operation_id);
        }

        return $operations->exists();
    }

    /**
     * Qual condição reprovou.
     *
     * A ordem é a da leitura do operador, não a do SQL: primeiro o estado dos
     * principais (que ele resolve no cadastro de usuários), depois a permissão
     * (que resolve na role), por último o assignment (que resolve na operação).
     * Delegante antes de delegado porque é a autoridade dele que está sendo
     * emprestada -- se ele não a tem, o resto não importa.
     *
     * @param  list<MeasurementResponsibility>  $covered
     */
    private function ineffectivenessReason(
        ResponsibilityDelegation $delegation,
        array $covered,
    ): ?DelegationIneffectivenessReason {
        if ($covered === []) {
            return DelegationIneffectivenessReason::ScopeMismatch;
        }

        $responsibility = $covered[0];
        $delegator = User::query()->whereKey($delegation->delegator_user_id);
        $delegate = User::query()->whereKey($delegation->delegate_user_id);

        return match (true) {
            ! $this->scopePrincipalIsActive(clone $delegator)->exists() => DelegationIneffectivenessReason::DelegatorInactive,
            ! $this->scopePrincipalIsApproved(clone $delegator)->exists() => DelegationIneffectivenessReason::DelegatorUnapproved,
            ! (clone $delegator)->permission($responsibility->permission())->exists() => DelegationIneffectivenessReason::DelegatorMissingPermission,
            ! $this->scopePrincipalIsActive(clone $delegate)->exists() => DelegationIneffectivenessReason::DelegateInactive,
            ! $this->scopePrincipalIsApproved(clone $delegate)->exists() => DelegationIneffectivenessReason::DelegateUnapproved,
            ! (clone $delegate)->permission($responsibility->permission())->exists() => DelegationIneffectivenessReason::DelegateMissingPermission,
            default => DelegationIneffectivenessReason::DelegatorMissingAssignment,
        };
    }

    /** @param Builder<Operation> $query */
    public function scopeVisibleOperationsTo(Builder $query, User $user): Builder
    {
        if ($user->hasAnyRole(['super-admin', 'admin'])) {
            return $query;
        }

        $operationTable = $query->getModel()->getTable();
        $permittedResponsibilities = $this->permittedResponsibilitiesFor($user);

        return $query->where(function (Builder $visible) use ($user, $operationTable, $permittedResponsibilities): void {
            $visible->where(function (Builder $direct) use ($user): void {
                $direct->where('assigned_user_id', $user->getKey())
                    ->orWhere('responsible_user_id', $user->getKey())
                    ->orWhere('stage2_reviewer_user_id', $user->getKey())
                    ->orWhere('stage3_reviewer_user_id', $user->getKey())
                    ->orWhere('payment_manager_user_id', $user->getKey())
                    ->orWhere('payment_receipt_uploader_user_id', $user->getKey())
                    ->orWhere('payment_finalizer_user_id', $user->getKey())
                    ->orWhereHas('rejectionNotifyUsers', fn (Builder $users): Builder => $users->whereKey($user->getKey()));
            });

            if ($permittedResponsibilities === []) {
                return;
            }

            $visible->orWhere(fn (Builder $delegated): Builder => $this->applyEffectiveDelegatedVisibility(
                $delegated,
                $user,
                $operationTable,
                $permittedResponsibilities,
            ));
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        $data['scope_operation_id'] = filled($data['scope_operation_id'] ?? null)
            ? (int) $data['scope_operation_id']
            : null;
        $data['scope_stage'] = filled($data['scope_stage'] ?? null)
            ? (int) $data['scope_stage']
            : null;
        $data['scope_responsibility'] = filled($data['scope_responsibility'] ?? null)
            ? (string) $data['scope_responsibility']
            : null;
        $data['reason'] = Str::squish((string) ($data['reason'] ?? ''));
        $data['starts_at'] = CarbonImmutable::parse($data['starts_at']);
        $data['ends_at'] = CarbonImmutable::parse($data['ends_at']);

        if ($data['scope_type'] !== ResponsibilityDelegation::SCOPE_STAGE) {
            $data['scope_stage'] = null;
            $data['scope_responsibility'] = null;
        }

        if ($data['scope_type'] === ResponsibilityDelegation::SCOPE_GLOBAL) {
            $data['scope_operation_id'] = null;
        }

        if ($data['scope_type'] === ResponsibilityDelegation::SCOPE_STAGE
            && $data['scope_responsibility'] === null
            && $data['scope_stage'] !== null) {
            $responsibilities = MeasurementResponsibility::forStage($data['scope_stage']);

            if (count($responsibilities) === 1) {
                $data['scope_responsibility'] = $responsibilities[0]->value;
            }
        }

        return $data;
    }

    private function assertCanCreate(User $actor, int $delegatorId): void
    {
        if ($actor->hasAnyRole(['super-admin', 'admin']) || $actor->can('delegations.manage')) {
            return;
        }

        if ((int) $actor->getKey() !== $delegatorId) {
            throw ValidationException::withMessages(['delegator_user_id' => 'Você só pode delegar suas próprias responsabilidades.']);
        }

        if (! $actor->can('delegations.create')) {
            throw ValidationException::withMessages(['delegator_user_id' => 'Sem permissão para criar delegações.']);
        }
    }

    private function assertCanRevoke(User $actor, ResponsibilityDelegation $delegation): void
    {
        if ($actor->hasAnyRole(['super-admin', 'admin']) || $actor->can('delegations.manage')) {
            return;
        }

        if ((int) $actor->getKey() !== (int) $delegation->delegator_user_id
            || ! $actor->can('delegations.revoke')) {
            throw ValidationException::withMessages(['delegation' => 'Você não pode revogar esta delegação.']);
        }
    }

    /** @param array<string, mixed> $data */
    private function validateBusinessRules(User $delegator, User $delegate, array $data): void
    {
        if ((int) $delegator->getKey() === (int) $delegate->getKey()) {
            throw ValidationException::withMessages(['delegate_user_id' => 'Não é possível delegar para si mesmo.']);
        }

        if (! $delegator->isActive() || ! $delegator->isApproved()) {
            throw ValidationException::withMessages(['delegator_user_id' => 'Delegante deve estar ativo e aprovado.']);
        }

        if (! $delegate->isActive() || ! $delegate->isApproved()) {
            throw ValidationException::withMessages(['delegate_user_id' => 'Delegado deve estar ativo e aprovado.']);
        }

        if ($data['reason'] === '') {
            throw ValidationException::withMessages(['reason' => 'Motivo é obrigatório.']);
        }

        if (Str::length($data['reason']) > 1000) {
            throw ValidationException::withMessages(['reason' => 'O motivo deve ter no máximo 1000 caracteres.']);
        }

        if ($data['ends_at']->lessThanOrEqualTo($data['starts_at'])) {
            throw ValidationException::withMessages(['ends_at' => 'Término deve ser após o início.']);
        }

        if ($data['ends_at']->isPast()) {
            throw ValidationException::withMessages(['ends_at' => 'Delegação já expirada no momento da criação.']);
        }

        if (! array_key_exists($data['scope_type'], ResponsibilityDelegation::SCOPE_OPTIONS)) {
            throw ValidationException::withMessages(['scope_type' => 'Escopo inválido.']);
        }

        if ($data['scope_type'] === ResponsibilityDelegation::SCOPE_OPERATION
            && $data['scope_operation_id'] === null) {
            throw ValidationException::withMessages(['scope_operation_id' => 'Operação é obrigatória para este escopo.']);
        }

        if ($data['scope_operation_id'] !== null
            && ! Operation::query()->whereKey($data['scope_operation_id'])->exists()) {
            throw ValidationException::withMessages(['scope_operation_id' => 'Operação informada não existe.']);
        }

        if ($data['scope_type'] !== ResponsibilityDelegation::SCOPE_STAGE) {
            return;
        }

        $responsibility = MeasurementResponsibility::tryFrom((string) $data['scope_responsibility']);

        if (! $responsibility instanceof MeasurementResponsibility) {
            throw ValidationException::withMessages(['scope_responsibility' => 'Responsabilidade é obrigatória para o escopo por etapa.']);
        }

        if ((int) $data['scope_stage'] !== $responsibility->stage()) {
            throw ValidationException::withMessages(['scope_responsibility' => 'A responsabilidade não pertence à etapa informada.']);
        }
    }

    /** @param array<string, mixed> $data */
    private function assertNoOverlapping(array $data): void
    {
        $conflict = ResponsibilityDelegation::query()
            ->where('delegator_user_id', $data['delegator_user_id'])
            ->whereNull('revoked_at')
            ->where('starts_at', '<=', $data['ends_at'])
            ->where('ends_at', '>=', $data['starts_at'])
            ->lockForUpdate()
            ->get()
            ->contains(fn (ResponsibilityDelegation $delegation): bool => $this->scopesConflict($delegation, $data));

        if ($conflict) {
            throw ValidationException::withMessages(['delegate_user_id' => 'Já existe delegação conflitante deste delegante para o escopo e período informados.']);
        }
    }

    /**
     * Redundância bloqueada (P2.3, decisão 2): duas delegações do mesmo
     * delegante cujos escopos podem conferir a mesma autoridade não coexistem,
     * ainda que para delegados diferentes. Para trocar de escopo, revogue a
     * anterior e crie a nova -- sem precedence entre linhas, sem revogação
     * ambígua. Escopos realmente disjuntos continuam convivendo.
     *
     * @param  array<string, mixed>  $data
     */
    private function scopesConflict(ResponsibilityDelegation $existing, array $data): bool
    {
        return DelegationAuthorityContext::fromDelegation($existing)
            ->intersects(DelegationAuthorityContext::fromNormalizedData($data));
    }

    /**
     * Existe ciclo bloqueante somente quando a autoridade pode voltar ao
     * delegante original por um caminho cujos contextos funcionais se
     * intersectam e cujas vigências podem coexistir -- ou seja, quando há
     * possibilidade real de reciprocidade no mesmo contexto.
     *
     * Cada caminho carrega o contexto acumulado (interseção dos escopos) e a
     * janela acumulada (interseção dos períodos). Se qualquer uma esvazia, o
     * caminho não representa ciclo efetivo e é abandonado ali. A → B / Operação
     * X com B → A / Operação Y não é reciprocidade: são autoridades diferentes.
     *
     * Isto é regra de integridade de cadastro e nada mais: nenhuma cadeia
     * percorrida aqui torna autoridade delegada transitiva -- a autorização
     * continua decidida por delegação efetiva individual.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertNoCycle(array $data): void
    {
        $delegatorId = (int) $data['delegator_user_id'];
        $delegateId = (int) $data['delegate_user_id'];

        $seedContext = DelegationAuthorityContext::fromNormalizedData($data);
        // A janela acumulada nunca é maior que a da nova delegação, e o que já
        // passou não pode gerar reciprocidade daqui para a frente.
        $seedWindow = DelegationWindow::between($data['starts_at'], $data['ends_at'])
            ?->notBefore(CarbonImmutable::now());

        if (! $seedWindow instanceof DelegationWindow) {
            return;
        }

        /** @var list<array{user: int, context: DelegationAuthorityContext, window: DelegationWindow}> $frontier */
        $frontier = [['user' => $delegateId, 'context' => $seedContext, 'window' => $seedWindow]];
        $visited = [$this->pathStateKey($delegateId, $seedContext, $seedWindow) => true];

        while ($frontier !== []) {
            $edges = $this->outgoingEdges(array_column($frontier, 'user'), $seedWindow);
            $next = [];

            foreach ($frontier as $state) {
                foreach ($edges[$state['user']] ?? [] as $edge) {
                    $context = $state['context']->intersect(DelegationAuthorityContext::fromDelegation($edge));
                    $edgeWindow = DelegationWindow::fromDelegation($edge);
                    $window = $edgeWindow instanceof DelegationWindow
                        ? $state['window']->intersect($edgeWindow)
                        : null;

                    if (! $context instanceof DelegationAuthorityContext || ! $window instanceof DelegationWindow) {
                        continue;
                    }

                    $candidate = (int) $edge->delegate_user_id;

                    if ($candidate === $delegatorId) {
                        throw ValidationException::withMessages([
                            'delegate_user_id' => 'Ciclo de delegação detectado: a autoridade retornaria ao delegante no mesmo contexto e período.',
                        ]);
                    }

                    $key = $this->pathStateKey($candidate, $context, $window);

                    if (isset($visited[$key])) {
                        continue;
                    }

                    $visited[$key] = true;
                    $next[] = ['user' => $candidate, 'context' => $context, 'window' => $window];
                }
            }

            if (count($visited) > self::MAX_CYCLE_PATH_STATES) {
                throw ValidationException::withMessages(['delegate_user_id' => 'Não foi possível validar o grafo de delegações com segurança.']);
            }

            $frontier = $next;
        }
    }

    /**
     * Uma consulta por nível da busca: nenhuma aresta fora da janela da nova
     * delegação pode compor um caminho efetivo, porque toda janela acumulada é
     * subconjunto dela.
     *
     * A busca trava o que lê, inclusive as ausências. Sem isto, duas transações
     * podem percorrer o grafo ao mesmo tempo, cada uma sem ver a aresta que a
     * outra ainda não gravou, e gravar em conjunto um ciclo que nenhuma das duas
     * conseguiria criar sozinha. O lock dos dois usuários no início de
     * `createDelegation()` já serializa os pares que compartilham delegante ou
     * delegado -- reciprocidade direta A/B, e qualquer cadeia em que as duas
     * arestas novas se tocam --, mas não alcança arestas disjuntas: com
     * B → C e D → A já gravadas, A → B e C → D fecham A → B → C → D → A sem ter
     * um único usuário em comum.
     *
     * Em InnoDB o `FOR UPDATE` aqui resolve isto sem lock global. A consulta usa
     * `rd_delegator_idx (delegator_user_id, revoked_at)` por igualdade nas duas
     * colunas, então cada delegante visitado recebe next-key lock na sua faixa
     * -- e o intervalo vazio recebe gap lock, que é o caso que importa: é
     * exatamente ali que a transação concorrente quer inserir. Como o caminho
     * entre a aresta nova de uma e o ponto de inserção da outra é formado só por
     * arestas já gravadas, cada busca sempre visita o delegante onde a outra vai
     * gravar. As duas se bloqueiam mutuamente: ou uma espera e depois enxerga a
     * aresta da outra (e recusa por ciclo), ou o par forma deadlock e o
     * `DB::transaction(..., 3)` de `createDelegation()` refaz a verificação do
     * zero -- sem Activity parcial, porque o log está dentro da transação.
     *
     * @param  list<int>  $delegatorIds
     * @return array<int, list<ResponsibilityDelegation>>
     */
    private function outgoingEdges(array $delegatorIds, DelegationWindow $horizon): array
    {
        $edges = ResponsibilityDelegation::query()
            ->whereNull('revoked_at')
            ->whereIn('delegator_user_id', array_values(array_unique($delegatorIds)))
            ->where('starts_at', '<=', $horizon->endsAt)
            ->where('ends_at', '>=', $horizon->startsAt)
            ->lockForUpdate()
            ->get([
                'id',
                'delegator_user_id',
                'delegate_user_id',
                'scope_type',
                'scope_operation_id',
                'scope_stage',
                'scope_responsibility',
                'starts_at',
                'ends_at',
                'revoked_at',
            ]);

        $grouped = [];

        foreach ($edges as $edge) {
            $grouped[(int) $edge->delegator_user_id][] = $edge;
        }

        return $grouped;
    }

    private function pathStateKey(int $userId, DelegationAuthorityContext $context, DelegationWindow $window): string
    {
        return $userId.'|'.$context->signature().'|'.$window->signature();
    }

    /** @param array<string, mixed> $data */
    private function assertDelegatorHasAuthority(User $delegator, array $data): void
    {
        if ($data['scope_type'] === ResponsibilityDelegation::SCOPE_STAGE) {
            $responsibility = MeasurementResponsibility::tryFrom((string) $data['scope_responsibility']);

            if (! $responsibility instanceof MeasurementResponsibility
                || ! $delegator->can($responsibility->permission())) {
                throw ValidationException::withMessages(['delegator_user_id' => 'Delegante não possui a permissão exigida por esta responsabilidade.']);
            }

            $query = Operation::query()->where($responsibility->operationColumn(), $delegator->getKey());

            if ($data['scope_operation_id'] !== null) {
                $query->whereKey($data['scope_operation_id']);
            }

            if (! $query->exists()) {
                throw ValidationException::withMessages(['delegator_user_id' => 'Delegante não possui diretamente esta responsabilidade.']);
            }

            return;
        }

        $delegableResponsibilities = collect(MeasurementResponsibility::cases())
            ->filter(fn (MeasurementResponsibility $responsibility): bool => $delegator->can($responsibility->permission()));

        if ($delegableResponsibilities->isEmpty()) {
            throw ValidationException::withMessages(['delegator_user_id' => 'Delegante não possui permissão operacional delegável.']);
        }

        $operationQuery = Operation::query()->where(function (Builder $responsibilities) use ($delegator, $delegableResponsibilities): void {
            foreach ($delegableResponsibilities as $responsibility) {
                $responsibilities->orWhere($responsibility->operationColumn(), $delegator->getKey());
            }
        });

        if ($data['scope_type'] === ResponsibilityDelegation::SCOPE_OPERATION) {
            $operationQuery->whereKey($data['scope_operation_id']);
        }

        if (! $operationQuery->exists()) {
            throw ValidationException::withMessages(['delegator_user_id' => 'Delegante não possui responsabilidade direta no escopo informado.']);
        }
    }

    /** @return list<MeasurementResponsibility> */
    private function permittedResponsibilitiesFor(User $user): array
    {
        return array_values(array_filter(
            MeasurementResponsibility::cases(),
            fn (MeasurementResponsibility $responsibility): bool => $user->can($responsibility->permission()),
        ));
    }

    /** @return Builder<ResponsibilityDelegation> */
    private function effectiveDelegationsForResponsibilityQuery(
        MeasurementResponsibility $responsibility,
    ): Builder {
        return ResponsibilityDelegation::query()
            ->active()
            ->whereHas(
                'delegator',
                fn (Builder $delegator): Builder => $this->scopeEffectivePrincipal(
                    $delegator,
                    $responsibility->permission(),
                ),
            )
            ->whereHas(
                'delegate',
                fn (Builder $delegate): Builder => $this->scopeEffectivePrincipal(
                    $delegate,
                    $responsibility->permission(),
                ),
            );
    }

    /**
     * As três condições que fazem de um usuário um principal efetivo.
     *
     * Ficam decompostas, e não inline, porque {@see self::ineffectivenessReason()}
     * as aplica uma a uma para dizer qual falhou. Compostas aqui, avaliadas lá:
     * uma fonte só, sem uma segunda leitura da mesma regra.
     *
     * @param  Builder<User>  $query
     */
    private function scopeEffectivePrincipal(Builder $query, string $permission): Builder
    {
        return $this->scopePrincipalIsApproved($this->scopePrincipalIsActive($query))
            ->permission($permission);
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    private function scopePrincipalIsActive(Builder $query): Builder
    {
        return $query->where(function (Builder $active): void {
            $active->where('is_active', true)
                ->orWhereNull('is_active');
        });
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    private function scopePrincipalIsApproved(Builder $query): Builder
    {
        return $query->whereNotNull('approved_at');
    }

    /**
     * @param  Builder<Operation>  $query
     * @param  list<MeasurementResponsibility>  $responsibilities
     * @return Builder<Operation>
     */
    private function applyEffectiveDelegatedVisibility(
        Builder $query,
        User $delegate,
        string $operationTable,
        array $responsibilities,
    ): Builder {
        foreach ($responsibilities as $responsibility) {
            $delegationTable = (new ResponsibilityDelegation)->getTable();
            $effectiveDelegation = $this->effectiveDelegationsForResponsibilityQuery($responsibility)
                ->selectRaw('1')
                ->where($delegationTable.'.delegate_user_id', $delegate->getKey())
                ->whereColumn(
                    $delegationTable.'.delegator_user_id',
                    $operationTable.'.'.$responsibility->operationColumn(),
                );

            $this->scopeDelegationToOperationTable(
                $effectiveDelegation,
                $operationTable,
                $responsibility,
            );

            $query->orWhereExists($effectiveDelegation->getQuery());
        }

        return $query;
    }

    /** @param Builder<ResponsibilityDelegation> $query */
    private function scopeDelegationToOperationTable(
        Builder $query,
        string $operationTable,
        MeasurementResponsibility $responsibility,
    ): Builder {
        $delegationTable = $query->getModel()->getTable();

        return $query->where(function (Builder $scope) use ($responsibility, $operationTable, $delegationTable): void {
            $scope->where($delegationTable.'.scope_type', ResponsibilityDelegation::SCOPE_GLOBAL)
                ->orWhere(function (Builder $operation) use ($operationTable, $delegationTable): void {
                    $operation->where($delegationTable.'.scope_type', ResponsibilityDelegation::SCOPE_OPERATION)
                        ->whereColumn($delegationTable.'.scope_operation_id', $operationTable.'.id');
                })
                ->orWhere(function (Builder $stage) use ($responsibility, $operationTable, $delegationTable): void {
                    $stage->where($delegationTable.'.scope_type', ResponsibilityDelegation::SCOPE_STAGE)
                        ->where($delegationTable.'.scope_stage', $responsibility->stage())
                        ->where(function (Builder $operationScope) use ($operationTable, $delegationTable): void {
                            $operationScope->whereNull($delegationTable.'.scope_operation_id')
                                ->orWhereColumn($delegationTable.'.scope_operation_id', $operationTable.'.id');
                        })
                        ->where(function (Builder $responsibilityScope) use ($responsibility, $delegationTable): void {
                            $responsibilityScope->where($delegationTable.'.scope_responsibility', $responsibility->value);

                            if (MeasurementResponsibility::primaryForStage($responsibility->stage()) === $responsibility) {
                                $responsibilityScope->orWhereNull($delegationTable.'.scope_responsibility');
                            }
                        });
                });
        });
    }

    private function delegationCanCoverResponsibility(
        ResponsibilityDelegation $delegation,
        MeasurementResponsibility $responsibility,
    ): bool {
        if ($delegation->scope_type !== ResponsibilityDelegation::SCOPE_STAGE) {
            return in_array($delegation->scope_type, [
                ResponsibilityDelegation::SCOPE_GLOBAL,
                ResponsibilityDelegation::SCOPE_OPERATION,
            ], true);
        }

        if ((int) $delegation->scope_stage !== $responsibility->stage()) {
            return false;
        }

        // Mesma canonicalização de ResponsibilityDelegation::matchesResponsibility().
        if ($delegation->scope_responsibility !== null) {
            return $delegation->scope_responsibility === $responsibility->value;
        }

        return MeasurementResponsibility::primaryForStage($responsibility->stage()) === $responsibility;
    }

    /** @return array<string, mixed> */
    private function auditProperties(ResponsibilityDelegation $delegation): array
    {
        return [
            'delegator_user_id' => $delegation->delegator_user_id,
            'delegate_user_id' => $delegation->delegate_user_id,
            'scope_type' => $delegation->scope_type,
            'scope_operation_id' => $delegation->scope_operation_id,
            'scope_stage' => $delegation->scope_stage,
            'scope_responsibility' => $delegation->scope_responsibility,
            'starts_at' => $delegation->starts_at?->toDateTimeString(),
            'ends_at' => $delegation->ends_at?->toDateTimeString(),
            'reason' => $delegation->reason,
        ];
    }
}
