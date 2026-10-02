<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\Enums\ConstructionUnitExchangeKind;
use App\Enums\ContractStatus;
use App\Exceptions\ConstructionUnitExchangeException;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\ConstructionUnitExchange;
use App\Models\Contract;
use App\Models\Emission;
use App\Models\SalesBoardCycle;
use App\Models\User;
use App\Support\ActivityLog\LogBatch;
use App\Support\Contracts\ContractOccupancy;
use App\Support\Dates\InclusiveDateBound;
use App\Support\Money\IntegerMoney;
use App\Support\SalesBoards\PublishedCompetenceBoundary;
use App\Support\SalesBoards\SalesBoardApprovalAuthority;
use App\Support\SalesBoards\SalesBoardPlausibility;
use App\Support\SalesBoards\UnitRetirementTimeline;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Permuta de unidade: a posição inicial e o que a Gestão muda depois dela.
 *
 * A posição inicial de permuta é declarada enquanto o Quadro de Vendas ainda não
 * usou a obra: a Emissão está em elaboração, não usa o Quadro automatizado e a
 * obra não tem nenhuma competência apurada ({@see self::initialPositionIsFrozen()}).
 * Quem declara é quem estrutura a operação (`constructions.update`), com motivo.
 *
 * Depois disso a permuta decide a classificação "permutado" de cada
 * competência, e mudá-la é decisão da Gestão (`sales-boards.approve`):
 * "Registrar permuta extraordinária", "Encerrar permuta" e "Substituir
 * permuta", com motivo e autor gravados na própria permuta e trilha no
 * Activitylog. O que congela a posição inicial é o Quadro ter usado a obra, e
 * não o status da Emissão: uma Emissão devolvida a "Em Elaboração" depois de ter
 * competência apurada continua congelada -- senão a ida e volta ao rascunho
 * liberaria ao editor uma permuta sem a Gestão --, e por isso a extraordinária
 * vale nela.
 *
 * Encerrar a permuta distrata, na mesma transação, o contrato de permuta que
 * continuaria ocupando a unidade ({@see self::end()}): sem isso o contrato
 * marcado como permutado ficaria na unidade sem permuta, e a obra inteira
 * bloquearia na apuração seguinte. É consequência restrita da decisão da Gestão
 * -- o contrato da própria permuta, na data do encerramento, confirmado pelo id
 * no modal --, gravada pelo model (trilha `contracts`) e explicada por uma
 * atividade manual no mesmo lote. Substituir a permuta ({@see self::substitute()})
 * corrige valor ou vigência sem distratar nada: encerra a atual e registra a
 * nova com o mesmo contrato.
 *
 * Permuta sem valor não existe: zero é o marcador de "sem valor" e não compõe o
 * Quadro, então todo registro recusa valor menor ou igual a zero.
 *
 * Quatro proteções que não são regra nova, e sim o que já vale no Quadro:
 *
 * - nenhuma data é anterior a 01/01/1990 -- o mesmo piso das importações e
 *   dos formulários de contrato e de parcela
 *   ({@see SalesBoardPlausibility::MINIMUM_YEAR}). A derivação bloqueia a
 *   permuta vigente que começa antes dele, e a permuta não se edita: um ano
 *   digitado com um dígito a menos (0026) prenderia as competências seguintes;
 * - a data não alcança competência já publicada -- a posição publicada é
 *   imutável, como na política de desconto e na baixa de unidade
 *   ({@see PublishedCompetenceBoundary});
 * - uma unidade não tem duas permutas vigentes ao mesmo tempo -- a derivação
 *   trata isso como permuta ambígua e bloqueia a competência;
 * - a permuta não cruza um período em que a unidade está baixada
 *   ({@see UnitRetirementTimeline}) -- baixada, a unidade não compõe o Quadro, e
 *   a derivação bloquearia a unidade baixada ocupada. Conferido depois do lock
 *   da unidade, que a baixa também trava.
 *
 * As escritas da Gestão seguem a ordem de locks do Quadro: os ciclos em aberto
 * que a data alcança, do mês mais recente para o mais antigo, depois a unidade,
 * a permuta e os contratos. A aprovação trava o ciclo antes de derivar a fonte,
 * e é essa espera que impede uma permuta commitada no meio da aprovação de cair
 * dentro da competência que ela publica.
 *
 * Nada é apagado: encerrar grava o fim da vigência, e a permuta continua
 * consultável. A competência em andamento percebe a mudança pela verificação
 * de fonte e é recalculada pelo caminho de sempre.
 *
 * Dois caminhos de regularização para o que ficou de antes destas regras, os
 * dois da Gestão e pelas mesmas travas: a permuta encerrada sem o distrato do
 * contrato de permuta ({@see self::cancelEndedExchangeContract()}) e a
 * permuta com início anterior a 1990, corrigida por "Substituir permuta"
 * ({@see self::substitute()}).
 */
class ConstructionUnitExchangeService
{
    /**
     * O evento da atividade manual que explica o distrato gravado pelo
     * encerramento da permuta, na trilha `contracts`.
     */
    public const EXCHANGE_END_CANCELLATION_EVENT = 'distrato_por_encerramento_de_permuta';

    /**
     * O evento da atividade manual que explica o distrato gravado depois, pela
     * regularização de uma permuta encerrada sem ele.
     */
    public const ENDED_EXCHANGE_CANCELLATION_EVENT = 'distrato_de_contrato_de_permuta_encerrada';

    /**
     * A posição inicial de permuta da unidade já foi usada pelo Quadro?
     *
     * Congela quando a Emissão não está em elaboração, quando ela usa o Quadro
     * automatizado ou quando a obra tem qualquer ciclo do Quadro -- em qualquer
     * situação, inclusive cancelado: uma competência apurada já congelou uma
     * posição com a permuta daquele dia. Sem Emissão não há elaboração a
     * proteger, e a posição conta como congelada.
     */
    public function initialPositionIsFrozen(ConstructionUnit $unit): bool
    {
        $emissionId = Construction::query()->whereKey($unit->construction_id)->value('emission_id');
        $emission = $emissionId === null ? null : Emission::query()->find($emissionId);

        if (! $emission instanceof Emission || ! $emission->isInDraft() || $emission->usesAutomatedSalesBoard()) {
            return true;
        }

        return SalesBoardCycle::query()
            ->where('construction_id', $unit->construction_id)
            ->exists();
    }

    /**
     * "Declarar permuta inicial": a posição de permuta da unidade antes de o
     * Quadro usar a obra.
     *
     * Era um `create` direto da tela; agora passa pelas mesmas regras de
     * qualquer escrita de permuta -- ator com `constructions.update`, motivo de
     * pelo menos dez caracteres, a unidade travada, o contrato da própria
     * unidade e nenhuma sobreposição -- e é recusada quando a posição já
     * congelou. Uma chamada forjada chega aqui sem passar pela tela.
     *
     * @throws AuthorizationException
     */
    public function declareBaseline(
        ConstructionUnit $unit,
        ?User $actor,
        mixed $exchangeValue,
        CarbonImmutable $effectiveFrom,
        ?int $contractId,
        string $reason,
    ): ConstructionUnitExchange {
        if ($actor === null) {
            throw ConstructionUnitExchangeException::actorRequired();
        }

        if (! $actor->can('constructions.update')) {
            throw new AuthorizationException('Declarar a permuta inicial exige a permissão de editar empreendimentos.');
        }

        $reason = $this->normalizeReason($reason);
        $effectiveFrom = $effectiveFrom->startOfDay();
        $this->assertPlausibleDate($effectiveFrom);
        $cents = $this->exchangeCents($exchangeValue);

        return DB::transaction(function () use ($unit, $actor, $cents, $effectiveFrom, $contractId, $reason): ConstructionUnitExchange {
            $locked = $this->lockUnit($unit);

            if ($this->initialPositionIsFrozen($locked)) {
                throw ConstructionUnitExchangeException::initialPositionFrozen();
            }

            $this->assertContractOfUnit($locked, $contractId);
            $this->assertNoOverlap($locked, $effectiveFrom);
            $this->assertNotRetiredFrom($locked, $effectiveFrom);

            return $locked->exchanges()->create([
                'exchange_value' => IntegerMoney::decimalString($cents),
                'effective_from' => $effectiveFrom->toDateString(),
                'ended_on' => null,
                'contract_id' => $contractId,
                'kind' => ConstructionUnitExchangeKind::Baseline,
                'reason' => $reason,
                'created_by_id' => $actor->getKey(),
            ]);
        });
    }

    /**
     * "Registrar permuta extraordinária": a permuta nova da Gestão, depois que
     * o Quadro usou a obra.
     */
    public function registerExtraordinary(
        ConstructionUnit $unit,
        ?User $actor,
        mixed $exchangeValue,
        CarbonImmutable $effectiveFrom,
        ?int $contractId,
        string $reason,
    ): ConstructionUnitExchange {
        $actor = $this->authorize($actor);
        $reason = $this->normalizeReason($reason);
        $effectiveFrom = $effectiveFrom->startOfDay();
        $this->assertPlausibleDate($effectiveFrom);
        $cents = $this->exchangeCents($exchangeValue);

        return DB::transaction(function () use ($unit, $actor, $cents, $effectiveFrom, $contractId, $reason): ConstructionUnitExchange {
            PublishedCompetenceBoundary::lockOpenCyclesReachedBy((int) $unit->construction_id, $effectiveFrom);
            $locked = $this->lockUnit($unit);

            $this->assertInitialPositionFrozen($locked);
            $this->assertAfterPublishedCompetences($locked, $effectiveFrom);
            $this->assertContractOfUnit($locked, $contractId);
            $this->assertNoOverlap($locked, $effectiveFrom);
            $this->assertNotRetiredFrom($locked, $effectiveFrom);

            return $locked->exchanges()->create([
                'exchange_value' => IntegerMoney::decimalString($cents),
                'effective_from' => $effectiveFrom->toDateString(),
                'ended_on' => null,
                'contract_id' => $contractId,
                'kind' => ConstructionUnitExchangeKind::Extraordinary,
                'reason' => $reason,
                'created_by_id' => $actor->getKey(),
            ]);
        });
    }

    /**
     * "Encerrar permuta": ela deixa de valer no próprio dia do encerramento
     * (vigência semiaberta), e o contrato de permuta que continuaria ocupando a
     * unidade é distratado na mesma data, na mesma transação.
     *
     * O alvo é o de {@see self::exchangeContractFor()}, recalculado sob o lock
     * da unidade, da permuta e dos contratos da unidade (nessa ordem). Ele só é
     * distratado se `$confirmedContractId` for o id dele: o modal mostra o
     * contrato e envia o id, e um modal aberto antes de alguém cadastrar o
     * contrato não distrata o que ninguém viu. Recusas, sem gravar nada:
     *
     * - data futura com contrato a distratar -- distrato é fato
     *   ({@see Contract::cancellationDateHasTakenEffect()}); sem contrato, a data
     *   futura continua aceita;
     * - contrato de permuta que só começa depois do encerramento;
     * - contrato com distrato já lançado depois da data pedida;
     * - mais de um contrato permutado ocupando a unidade.
     *
     * O distrato é gravado pelo model (status distratado e data do distrato,
     * trilha `contracts`), com uma atividade manual "Distrato pelo encerramento
     * da permuta" que leva o motivo e a permuta -- tudo no mesmo
     * {@see LogBatch} do encerramento. O contrato de venda comum que ocupava uma
     * unidade de permuta sem contrato nunca é tocado.
     *
     * @throws AuthorizationException
     */
    public function end(
        ConstructionUnitExchange $exchange,
        ?User $actor,
        CarbonImmutable $endedOn,
        string $reason,
        ?int $confirmedContractId = null,
    ): ConstructionUnitExchange {
        $actor = $this->authorize($actor);
        $reason = $this->normalizeReason($reason);
        $endedOn = $endedOn->startOfDay();
        $this->assertPlausibleDate($endedOn);
        $unit = ConstructionUnit::query()->findOrFail($exchange->construction_unit_id);

        return DB::transaction(function () use ($exchange, $unit, $actor, $endedOn, $reason, $confirmedContractId): ConstructionUnitExchange {
            PublishedCompetenceBoundary::lockOpenCyclesReachedBy((int) $unit->construction_id, $endedOn);
            $unit = $this->lockUnit($unit);
            $locked = $this->lockExchange($exchange);

            if ($locked->ended_on !== null) {
                throw ConstructionUnitExchangeException::alreadyEnded();
            }

            $this->assertInitialPositionFrozen($unit);

            $effectiveFrom = CarbonImmutable::parse($locked->effective_from->toDateString());

            if ($endedOn->lessThanOrEqualTo($effectiveFrom)) {
                throw ConstructionUnitExchangeException::endBeforeStart($effectiveFrom);
            }

            $this->assertAfterPublishedCompetences($unit, $endedOn);

            $target = $this->exchangeContractAmong($locked, $endedOn, $this->lockContractsOf($unit));

            if ($target !== null) {
                $this->assertExchangeContractCanBeCancelled($target, $endedOn);

                if ($confirmedContractId !== (int) $target->getKey()) {
                    throw ConstructionUnitExchangeException::contractCancellationNotConfirmed($target, $endedOn);
                }
            }

            return app(LogBatch::class)->withinBatch(function () use ($locked, $target, $actor, $endedOn, $reason): ConstructionUnitExchange {
                $locked->forceFill([
                    'ended_on' => $endedOn->toDateString(),
                    'ended_at' => CarbonImmutable::now(),
                    'ended_by_id' => $actor->getKey(),
                    'end_reason' => $reason,
                ])->save();

                if ($target !== null) {
                    $this->cancelExchangeContract($target, $locked, $actor, $endedOn, $reason);
                }

                return $locked->refresh();
            });
        });
    }

    /**
     * "Distratar contrato de permuta": o distrato que o encerramento da permuta
     * não gravou.
     *
     * Antes de o encerramento distratar o contrato de permuta, encerrar a
     * permuta só gravava o fim da vigência. O contrato marcado como permutado
     * continuou ocupando a unidade sem permuta, e toda competência depois do
     * encerramento bloqueia (`EXCHANGE_SOURCE_MISSING` e
     * `SETTLEMENT_UNDETERMINED`) -- e "Encerrar" de novo é recusado, porque a
     * permuta já está encerrada. Aqui a Gestão grava o que o encerramento
     * gravaria hoje: o distrato do contrato da própria permuta, na data do
     * encerramento, com as regras de {@see self::end()} -- ator da Gestão,
     * motivo, o contrato confirmado pelo id que o modal mostrou, a fronteira da
     * posição publicada, a data que já aconteceu e a ordem de locks (ciclos em
     * aberto, unidade, permuta, contratos).
     *
     * Só a permuta encerrada sem sucessora, e só o contrato ainda marcado como
     * permutado: a permuta substituída passa o contrato à que a sucedeu, e uma
     * venda comum na unidade nunca é alvo. O distrato é gravado pelo model
     * (trilha `contracts`) com uma atividade manual que leva o motivo e a
     * permuta, no mesmo {@see LogBatch}.
     *
     * Encerrada dentro de competência já publicada, a permuta não se
     * regulariza por aqui: como toda escrita de permuta da Gestão, esta não
     * grava data na posição publicada. O distrato com essa data é fato do
     * contrato e se registra no próprio contrato, de onde entra como movimento
     * extemporâneo na competência seguinte -- e a recusa diz isso.
     *
     * @throws AuthorizationException
     */
    public function cancelEndedExchangeContract(
        ConstructionUnitExchange $exchange,
        ?User $actor,
        string $reason,
        ?int $confirmedContractId,
    ): Contract {
        $actor = $this->authorize($actor);
        $reason = $this->normalizeReason($reason);
        $unit = ConstructionUnit::query()->findOrFail($exchange->construction_unit_id);

        if ($exchange->ended_on === null) {
            throw ConstructionUnitExchangeException::exchangeStillInForce();
        }

        /**
         * O fim de uma permuta encerrada não muda mais: lido antes das travas,
         * é ele que diz que ciclos o distrato alcança.
         */
        $endedOn = CarbonImmutable::parse($exchange->ended_on->toDateString());

        return DB::transaction(function () use ($exchange, $unit, $actor, $reason, $confirmedContractId, $endedOn): Contract {
            PublishedCompetenceBoundary::lockOpenCyclesReachedBy((int) $unit->construction_id, $endedOn);
            $unit = $this->lockUnit($unit);
            $locked = $this->lockExchange($exchange);

            $this->assertInitialPositionFrozen($unit);

            $lastPublished = PublishedCompetenceBoundary::lastPublishedMonth((int) $unit->construction_id, lockingRead: true);

            if (($lastPublished !== null) && $endedOn->lessThanOrEqualTo($lastPublished->endOfMonth())) {
                throw ConstructionUnitExchangeException::endedInsidePublishedCompetence($endedOn, $lastPublished);
            }

            $target = $this->endedExchangeContractAmong($locked, $unit, $this->lockContractsOf($unit));

            if ($target === null) {
                throw ConstructionUnitExchangeException::noExchangeContractToCancel($endedOn);
            }

            $this->assertExchangeContractCanBeCancelled($target, $endedOn);

            if ($confirmedContractId !== (int) $target->getKey()) {
                throw ConstructionUnitExchangeException::endedExchangeCancellationNotConfirmed($target, $endedOn);
            }

            return app(LogBatch::class)->withinBatch(function () use ($target, $locked, $actor, $endedOn, $reason): Contract {
                $this->cancelExchangeContract(
                    $target,
                    $locked,
                    $actor,
                    $endedOn,
                    $reason,
                    event: self::ENDED_EXCHANGE_CANCELLATION_EVENT,
                    description: 'Distrato do contrato de permuta encerrada',
                );

                return $target->refresh();
            });
        });
    }

    /**
     * O contrato que "Distratar contrato de permuta" distrataria, ou `null`.
     *
     * Busca pura, sem gravar nada -- é o que a tela pergunta para mostrar a
     * ação e o que o modal mostra antes de confirmar. Um contrato ambíguo
     * (dois permutados na unidade) não tem alvo: a ação não aparece, e a dica
     * manda corrigir os contratos.
     */
    public function endedExchangeContract(ConstructionUnitExchange $exchange): ?Contract
    {
        if ($exchange->ended_on === null) {
            return null;
        }

        $unit = ConstructionUnit::query()->find($exchange->construction_unit_id);

        if (! $unit instanceof ConstructionUnit) {
            return null;
        }

        try {
            return $this->endedExchangeContractAmong(
                $exchange,
                $unit,
                Contract::query()->where('construction_unit_id', $unit->getKey())->orderBy('id')->get(),
            );
        } catch (ConstructionUnitExchangeException) {
            return null;
        }
    }

    /**
     * "Substituir permuta": corrige o valor ou a vigência de uma permuta sem
     * distratar nada.
     *
     * A permuta atual é encerrada na data escolhida e uma nova é registrada a
     * partir dela, com o mesmo contrato, o valor novo, o motivo e o autor. A data
     * igual ao início da atual é a substituição desde o começo: a atual fica sem
     * vigência nenhuma, e a nova responde por todo o período. Nada é editado no
     * lugar -- a permuta que decidiu competências anteriores continua
     * consultável.
     *
     * Quem substitui segue o mesmo predicado das outras escritas
     * ({@see self::initialPositionIsFrozen()}): antes de o Quadro usar a obra é
     * a posição inicial, de quem estrutura a operação (`constructions.update`),
     * e a nova nasce como posição inicial; depois, é decisão da Gestão
     * (`sales-boards.approve`), não alcança competência publicada e a nova nasce
     * extraordinária.
     *
     * A permuta que começa antes de 1990 ({@see self::startsImplausibly()}) --
     * gravada antes do piso, quase sempre com o ano digitado errado -- é
     * corrigida aqui. Encerrá-la na data nova deixaria o início falso valendo
     * nos meses do meio, e a derivação bloqueia a permuta vigente que começa
     * antes do piso. Então ela deixa de valer onde a posição publicada permite:
     * no próprio início, se a obra não tem competência publicada (nunca valeu),
     * ou no primeiro dia depois da última publicada, onde continua valendo como
     * valia quando foi publicada. A nova vale a partir da data informada, que
     * passa pelo piso; entre as duas, a unidade fica sem permuta, como estava de
     * fato.
     *
     * @throws AuthorizationException
     */
    public function substitute(
        ConstructionUnitExchange $exchange,
        ?User $actor,
        CarbonImmutable $effectiveOn,
        mixed $exchangeValue,
        string $reason,
    ): ConstructionUnitExchange {
        if ($actor === null) {
            throw ConstructionUnitExchangeException::actorRequired();
        }

        $reason = $this->normalizeReason($reason);
        $effectiveOn = $effectiveOn->startOfDay();
        $this->assertPlausibleDate($effectiveOn);
        $cents = $this->exchangeCents($exchangeValue);
        $unit = ConstructionUnit::query()->findOrFail($exchange->construction_unit_id);

        /**
         * O início de uma permuta nunca muda no lugar, e lê-lo antes das travas
         * é seguro: é ele que diz desde que mês a correção de um início
         * implausível mexe -- e, portanto, que ciclos ela trava.
         */
        $reachedFrom = self::startsImplausibly($exchange)
            ? CarbonImmutable::parse($exchange->effective_from->toDateString())
            : $effectiveOn;

        return DB::transaction(function () use ($exchange, $unit, $actor, $effectiveOn, $reachedFrom, $cents, $reason): ConstructionUnitExchange {
            PublishedCompetenceBoundary::lockOpenCyclesReachedBy((int) $unit->construction_id, $reachedFrom);
            $unit = $this->lockUnit($unit);
            $locked = $this->lockExchange($exchange);

            if ($locked->ended_on !== null) {
                throw ConstructionUnitExchangeException::alreadyEnded();
            }

            $frozen = $this->initialPositionIsFrozen($unit);

            if ($frozen) {
                SalesBoardApprovalAuthority::authorize($actor);
                $this->assertAfterPublishedCompetences($unit, $effectiveOn);
            } elseif (! $actor->can('constructions.update')) {
                throw new AuthorizationException('Substituir a permuta da posição inicial exige a permissão de editar empreendimentos.');
            }

            $start = CarbonImmutable::parse($locked->effective_from->toDateString());
            $correctsImplausibleStart = self::startsImplausibly($locked);

            if ((! $correctsImplausibleStart) && $effectiveOn->lessThan($start)) {
                throw ConstructionUnitExchangeException::substitutionBeforeStart($start);
            }

            $endsOn = $correctsImplausibleStart ? $this->implausibleStartCut($unit, $start) : $effectiveOn;

            $this->assertNoOverlap($unit, $effectiveOn, $locked);
            $this->assertNotRetiredFrom($unit, $effectiveOn);

            return app(LogBatch::class)->withinBatch(function () use ($unit, $locked, $actor, $effectiveOn, $endsOn, $cents, $reason, $frozen): ConstructionUnitExchange {
                $locked->forceFill([
                    'ended_on' => $endsOn->toDateString(),
                    'ended_at' => CarbonImmutable::now(),
                    'ended_by_id' => $actor->getKey(),
                    'end_reason' => $reason,
                ])->save();

                return $unit->exchanges()->create([
                    'exchange_value' => IntegerMoney::decimalString($cents),
                    'effective_from' => $effectiveOn->toDateString(),
                    'ended_on' => null,
                    'contract_id' => $locked->contract_id,
                    'kind' => $frozen ? ConstructionUnitExchangeKind::Extraordinary : ConstructionUnitExchangeKind::Baseline,
                    'reason' => $reason,
                    'created_by_id' => $actor->getKey(),
                ]);
            });
        });
    }

    /**
     * O contrato de permuta que o encerramento na data distrataria, ou `null`.
     *
     * Busca pura, sem gravar nada -- é também o que o modal mostra antes de
     * confirmar:
     *
     * - a permuta aponta para um contrato: ele, qualquer que seja o status, se
     *   não estiver excluído e ainda não tiver distrato até a data (um distrato
     *   já lançado depois dela é recusado pelo encerramento);
     * - a permuta não tem contrato: o único ocupante da unidade marcado como
     *   permutado na data. Uma venda comum ocupando a unidade não é contrato de
     *   permuta e nunca é alvo.
     *
     * @throws ConstructionUnitExchangeException quando há mais de um ocupante permutado
     */
    public function exchangeContractFor(ConstructionUnitExchange $exchange, CarbonImmutable $endedOn): ?Contract
    {
        return $this->exchangeContractAmong(
            $exchange,
            $endedOn->startOfDay(),
            Contract::query()->where('construction_unit_id', $exchange->construction_unit_id)->orderBy('id')->get(),
        );
    }

    /**
     * A permuta começa antes de 01/01/1990 -- gravada antes do piso, quase
     * sempre com o ano digitado errado. É o caso que "Substituir permuta"
     * corrige desde o início.
     */
    public static function startsImplausibly(ConstructionUnitExchange $exchange): bool
    {
        return SalesBoardPlausibility::isBeforeMinimumYear($exchange->effective_from?->toDateString());
    }

    /**
     * A última competência publicada do empreendimento, se houver.
     *
     * A regra é a de {@see PublishedCompetenceBoundary}, a mesma da baixa de
     * unidade: publicada é a competência que tem publicação, não a que está com
     * o status Aprovado.
     */
    public function lastPublishedCompetence(ConstructionUnit $unit): ?CarbonImmutable
    {
        return PublishedCompetenceBoundary::lastPublishedMonth((int) $unit->construction_id);
    }

    private function authorize(?User $actor): User
    {
        if ($actor === null) {
            throw ConstructionUnitExchangeException::actorRequired();
        }

        SalesBoardApprovalAuthority::authorize($actor);

        return $actor;
    }

    /**
     * A unidade travada serializa os registros de permuta dela: dois
     * registros simultâneos não passam os dois pela checagem de sobreposição.
     */
    private function lockUnit(ConstructionUnit $unit): ConstructionUnit
    {
        return ConstructionUnit::query()
            ->whereKey($unit->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockExchange(ConstructionUnitExchange $exchange): ConstructionUnitExchange
    {
        return ConstructionUnitExchange::query()
            ->whereKey($exchange->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Os contratos da unidade, travados depois da unidade e da permuta -- a
     * ordem de locks do Quadro. São poucos, e o alvo do distrato é decidido
     * sobre eles já travados: entre ler e gravar, ninguém distrata nem
     * reclassifica o contrato que o encerramento vai distratar.
     *
     * @return Collection<int, Contract>
     */
    private function lockContractsOf(ConstructionUnit $unit): Collection
    {
        return Contract::query()
            ->where('construction_unit_id', $unit->getKey())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @param  Collection<int, Contract>  $contractsOfUnit
     *
     * @throws ConstructionUnitExchangeException quando há mais de um ocupante permutado
     */
    private function exchangeContractAmong(ConstructionUnitExchange $exchange, CarbonImmutable $endedOn, Collection $contractsOfUnit): ?Contract
    {
        if ($exchange->contract_id !== null) {
            /** @var Contract|null $contract */
            $contract = $contractsOfUnit->first(fn (Contract $contract): bool => (int) $contract->getKey() === (int) $exchange->contract_id);

            if (($contract === null)
                || (($contract->cancellation_date !== null) && ($contract->cancellation_date->toDateString() <= $endedOn->toDateString()))) {
                return null;
            }

            return $contract;
        }

        $occupants = $contractsOfUnit
            ->filter(fn (Contract $contract): bool => ($contract->status === ContractStatus::Exchanged)
                && ContractOccupancy::occupiesAt($contract, $endedOn))
            ->values();

        if ($occupants->count() > 1) {
            throw ConstructionUnitExchangeException::ambiguousExchangeContract($endedOn);
        }

        return $occupants->first();
    }

    /**
     * O contrato de permuta que continua na unidade depois de uma permuta
     * encerrada: o mesmo alvo do encerramento, na data dele, desde que nenhuma
     * outra permuta da unidade esteja valendo nessa data (a substituída deixa o
     * contrato com a sucessora) e que ele ainda esteja marcado como permutado e
     * sem distrato.
     *
     * @param  Collection<int, Contract>  $contractsOfUnit
     *
     * @throws ConstructionUnitExchangeException quando há mais de um ocupante permutado
     */
    private function endedExchangeContractAmong(ConstructionUnitExchange $exchange, ConstructionUnit $unit, Collection $contractsOfUnit): ?Contract
    {
        $endedOn = CarbonImmutable::parse($exchange->ended_on->toDateString());

        $succeeded = ConstructionUnitExchange::query()
            ->where('construction_unit_id', $unit->getKey())
            ->whereKeyNot($exchange->getKey())
            ->get()
            ->contains(fn (ConstructionUnitExchange $other): bool => $other->isEffectiveOn($endedOn));

        if ($succeeded) {
            return null;
        }

        $target = $this->exchangeContractAmong($exchange, $endedOn, $contractsOfUnit);

        return (($target !== null) && ($target->status === ContractStatus::Exchanged) && ($target->cancellation_date === null))
            ? $target
            : null;
    }

    /**
     * O distrato do contrato de permuta só é gravado se puder ser verdade.
     */
    private function assertExchangeContractCanBeCancelled(Contract $contract, CarbonImmutable $endedOn): void
    {
        if (! Contract::cancellationDateHasTakenEffect($endedOn->toDateString())) {
            throw ConstructionUnitExchangeException::cancellationMustHaveHappened($endedOn);
        }

        if (($contract->sale_date !== null) && ($contract->sale_date->toDateString() > $endedOn->toDateString())) {
            throw ConstructionUnitExchangeException::exchangeContractStartsAfterEnd($contract);
        }

        if ($contract->cancellation_date !== null) {
            throw ConstructionUnitExchangeException::exchangeContractCancelledLater($contract);
        }
    }

    /**
     * Distrata o contrato de permuta na data do encerramento.
     *
     * Pelo model, para que a trilha `contracts` registre status e data como
     * qualquer distrato; e com uma atividade própria, na mesma categoria, que
     * diz por que o contrato foi distratado sem ninguém ter editado o contrato.
     */
    private function cancelExchangeContract(
        Contract $contract,
        ConstructionUnitExchange $exchange,
        User $actor,
        CarbonImmutable $endedOn,
        string $reason,
        string $event = self::EXCHANGE_END_CANCELLATION_EVENT,
        string $description = 'Distrato pelo encerramento da permuta',
    ): void {
        $contract->forceFill([
            'status' => ContractStatus::Cancelled,
            'cancellation_date' => $endedOn->toDateString(),
        ])->save();

        activity('contracts')
            ->performedOn($contract)
            ->causedBy($actor)
            ->event($event)
            ->withProperties([
                'motivo' => $reason,
                'construction_unit_exchange_id' => (int) $exchange->getKey(),
            ])
            ->log($description);
    }

    /**
     * Antes de o Quadro usar a obra, a permuta é posição inicial, e não
     * decisão da Gestão: "Declarar permuta inicial" é o caminho.
     */
    private function assertInitialPositionFrozen(ConstructionUnit $unit): void
    {
        if (! $this->initialPositionIsFrozen($unit)) {
            throw ConstructionUnitExchangeException::initialPositionStillOpen();
        }
    }

    private function assertContractOfUnit(ConstructionUnit $unit, ?int $contractId): void
    {
        if (($contractId !== null)
            && ! Contract::query()->whereKey($contractId)->where('construction_unit_id', $unit->getKey())->exists()) {
            throw ConstructionUnitExchangeException::contractOfAnotherUnit();
        }
    }

    /**
     * Duas permutas vigentes ao mesmo tempo deixam a posição da unidade
     * indeterminada. Na substituição, a permuta substituída fica de fora: ela é
     * encerrada na mesma data.
     */
    private function assertNoOverlap(ConstructionUnit $unit, CarbonImmutable $effectiveFrom, ?ConstructionUnitExchange $replaced = null): void
    {
        $overlaps = ConstructionUnitExchange::query()
            ->where('construction_unit_id', $unit->getKey())
            ->when($replaced, fn (Builder $query, ConstructionUnitExchange $replaced): Builder => $query->whereKeyNot($replaced->getKey()))
            ->where(function ($query) use ($effectiveFrom): void {
                $query->whereNull('ended_on')->orWhere('ended_on', '>', InclusiveDateBound::upperBound($effectiveFrom));
            })
            ->exists();

        if ($overlaps) {
            throw ConstructionUnitExchangeException::overlapsExistingExchange($effectiveFrom);
        }
    }

    /**
     * A permuta nova vale de `$effectiveFrom` em diante, e nenhum período de
     * baixa da unidade pode cair nesse intervalo.
     */
    private function assertNotRetiredFrom(ConstructionUnit $unit, CarbonImmutable $effectiveFrom): void
    {
        $unitId = (int) $unit->getKey();
        $conflict = UnitRetirementTimeline::forUnits([$unitId])->conflictWith($unitId, $effectiveFrom->toDateString(), null);

        if ($conflict !== null) {
            throw ConstructionUnitExchangeException::unitRetired($conflict);
        }
    }

    /**
     * O valor da permuta em centavos, recusando o que não é valor -- inclusive
     * o zero, que é ausência de valor e não compõe o Quadro.
     */
    private function exchangeCents(mixed $exchangeValue): int
    {
        $cents = IntegerMoney::cents($exchangeValue);

        if (($cents === null) || ($cents <= 0)) {
            throw ConstructionUnitExchangeException::invalidValue();
        }

        return $cents;
    }

    /**
     * O corte da permuta de início implausível, com a fronteira lida com
     * `sharedLock()` depois dos locks dos ciclos e da unidade, como em
     * {@see self::assertAfterPublishedCompetences()}.
     */
    private function implausibleStartCut(ConstructionUnit $unit, CarbonImmutable $start): CarbonImmutable
    {
        $lastPublished = PublishedCompetenceBoundary::lastPublishedMonth((int) $unit->construction_id, lockingRead: true);

        return $lastPublished === null ? $start : $lastPublished->endOfMonth()->addDay()->startOfDay();
    }

    /**
     * O piso de 1990 de toda data de permuta. Conferido antes da transação:
     * não depende de nada que as travas protejam.
     */
    private function assertPlausibleDate(CarbonImmutable $date): void
    {
        if (SalesBoardPlausibility::isBeforeMinimumYear($date->toDateString())) {
            throw ConstructionUnitExchangeException::dateBeforeMinimumYear($date);
        }
    }

    /**
     * Lida com `sharedLock()`, depois dos locks dos ciclos em aberto e da
     * unidade: a publicação que uma aprovação acabou de commitar aparece aqui.
     */
    private function assertAfterPublishedCompetences(ConstructionUnit $unit, CarbonImmutable $date): void
    {
        $lastPublished = PublishedCompetenceBoundary::lastPublishedMonth((int) $unit->construction_id, lockingRead: true);

        if (($lastPublished !== null) && $date->lessThanOrEqualTo($lastPublished->endOfMonth())) {
            throw ConstructionUnitExchangeException::reachesPublishedCompetence($date, $lastPublished);
        }
    }

    private function normalizeReason(?string $reason): string
    {
        $reason = trim(strip_tags((string) $reason));

        if (mb_strlen($reason) < SalesBoardManagementDecisionService::MINIMUM_REASON_LENGTH) {
            throw ConstructionUnitExchangeException::reasonRequired(SalesBoardManagementDecisionService::MINIMUM_REASON_LENGTH);
        }

        return mb_substr($reason, 0, SalesBoardManagementDecisionService::MAXIMUM_REASON_LENGTH);
    }
}
