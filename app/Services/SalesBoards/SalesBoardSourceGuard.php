<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\ConstructionUnitExchange;
use App\Models\ConstructionUnitRetirement;
use App\Models\ConstructionUnitValue;
use App\Models\Contract;
use App\Models\Emission;
use App\Models\EmissionPuCurvePromotion;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuExternalBenchmark;
use App\Models\Measurement;
use App\Models\MeasurementPayment;
use App\Models\MeasurementPaymentReceiptEvidence;
use App\Models\ObligationAnchorEvent;
use App\Models\ObligationSeries;
use App\Models\Operation;
use App\Models\ResponsibilityDelegation;
use App\Models\SalesBoard;
use App\Models\SalesBoardAutomationTarget;
use App\Models\SalesBoardBuilderDivergence;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleLine;
use App\Models\SalesBoardCycleMovement;
use App\Models\SalesBoardRolloutEvent;
use App\Models\SalesBoardRolloutHomologation;
use App\Models\SalesBoardRolloutHomologationConstruction;
use App\Models\SalesBoardRolloutRecipient;
use App\Models\SalesDiscountPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * O que prende as fontes do Quadro de Vendas -- unidade, obra, contrato,
 * Emissão -- à história que já foi lida delas.
 *
 * A posição mensal é derivada de cadastros vivos. Depois que um ciclo congela
 * essa leitura, ou que um quadro é registrado, apagar ou mover a fonte reescreve
 * o passado em silêncio: a unidade some da competência já validada, a venda
 * deixa de ter existido, o empreendimento passa a ser somado por outra Emissão.
 * O banco protege parte disso com FK RESTRICT -- e responde com um erro cru --,
 * e outra parte ele nem protege: os quadros de uma obra vão embora em cascata,
 * com todo o histórico de versões.
 *
 * As regras moram aqui para serem uma só em três lugares: a policy, que decide
 * se a exclusão é oferecida e diz por quê; o formulário, que trava o campo; e o
 * model, que recusa a gravação vinda de qualquer outro caminho.
 *
 * Cada pergunta custa uma consulta só -- os `exists` viram subconsultas de um
 * mesmo `select` --, porque a policy é avaliada linha a linha nas tabelas.
 */
class SalesBoardSourceGuard
{
    /**
     * Por que a unidade não pode mais ser apagada nem trocar de empreendimento.
     *
     * Contrato (inclusive excluído), histórico de valor e permuta são a
     * história comercial dela; a linha congelada num ciclo é a unidade como a
     * construtora conferiu. A baixa é decisão da Gestão com motivo: apagar a
     * unidade apagaria a trilha dela, e mover a unidade levaria a baixa para
     * outra obra sem explicação. Qualquer um deles basta.
     *
     * @return list<string> motivos em linguagem de tela; vazio quando nada prende
     */
    public function unitAnchors(ConstructionUnit $unit): array
    {
        $unitIds = [$unit->getKey()];

        return $this->presentReasons(
            [
                ['tem contrato registrado, inclusive excluído', Contract::withTrashed()->whereIn('construction_unit_id', $unitIds)],
            ],
            $this->unitProbes($unitIds),
        );
    }

    /**
     * Por que a obra não pode mais trocar de Emissão.
     *
     * Os quadros e os ciclos ficam gravados com a Emissão em que foram
     * registrados, e os consumidores -- relatório mensal, Garantias -- os somam
     * por ela. Trocar a Emissão da obra deixaria a antiga somando um
     * empreendimento que não é mais dela e a nova sem posição nenhuma.
     *
     * @return list<string>
     */
    public function constructionEmissionAnchors(Construction $construction): array
    {
        return $this->presentReasons($this->salesBoardProbes([$construction->getKey()]));
    }

    /**
     * Por que a obra não pode ser apagada.
     *
     * Além do que já prende a Emissão, tudo o que a cascata sobre as unidades
     * encontraria: o banco recusaria parte, e apagaria o resto sem rastro.
     *
     * @return list<string>
     */
    public function constructionDeletionBlockers(Construction $construction): array
    {
        $reasons = $this->presentReasons(
            $this->salesBoardProbes([$construction->getKey()]),
            $this->constructionSourceProbes([$construction->getKey()]),
        );

        if ($construction->isReferencedByApprovedEngineering()) {
            $reasons[] = 'tem medição aprovada pela Engenharia';
        } elseif ($construction->hasMeasurementPlanHistory()) {
            // A mesma recusa do `deleting` da obra
            // (Construction::MEASUREMENT_PLAN_DELETION_REFUSAL), dita antes do clique.
            $reasons[] = 'tem plano de medição já ativado ou com medição registrada';
        }

        return $reasons;
    }

    /**
     * Por que a Emissão não pode ser apagada.
     *
     * A exclusão da Emissão desce em cascata por obras, unidades, quadros,
     * operações, curvas de PU e obrigações. Tudo o que essa cascata encontraria
     * protegido por RESTRICT entra aqui, para que a recusa venha com o motivo em
     * vez de um erro de constraint -- e os quadros entram porque, ao contrário,
     * iriam embora junto.
     *
     * A medição entra pelo mesmo motivo dos quadros. A cascata do banco desce de
     * `operations` para `measurements` e daí para análises, arquivos, pausas e
     * pagamentos sem passar por `Operation::deleting` nem por
     * `Measurement::deleting`: apagaria em silêncio a medição em análise e até o
     * pagamento ainda sem comprovante. Qualquer medição basta, como no
     * `deleting` da operação -- operação com medição se cancela, não se apaga.
     *
     * @return list<string>
     */
    public function emissionDeletionBlockers(Emission $emission): array
    {
        $emissionId = $emission->getKey();
        $constructionIds = Construction::query()->select('id')->where('emission_id', $emissionId);
        $operationIds = Operation::query()->select('id')->where('emission_id', $emissionId);

        return $this->presentReasons(
            [
                ['tem Quadro de Vendas registrado', SalesBoard::query()->where('emission_id', $emissionId)],
                ['tem ciclo do Quadro de Vendas', SalesBoardCycle::query()->where('emission_id', $emissionId)],
                ['tem histórico do rollout do Quadro de Vendas', SalesBoardRolloutHomologation::query()->where('emission_id', $emissionId)],
                ['tem histórico do rollout do Quadro de Vendas', SalesBoardRolloutEvent::query()->where('emission_id', $emissionId)],
                ['tem histórico do rollout do Quadro de Vendas', SalesBoardRolloutRecipient::query()->where('emission_id', $emissionId)],
            ],
            $this->salesBoardProbes($constructionIds),
            $this->constructionSourceProbes($constructionIds),
            [
                ['tem curva de PU gerada', EmissionPuCurveVersion::query()->where('emission_id', $emissionId)],
                ['tem curva de PU gerada', EmissionPuCurvePromotion::query()->where('emission_id', $emissionId)],
                ['tem validação externa de PU', EmissionPuExternalBenchmark::query()->where('emission_id', $emissionId)],
                ['tem operação com delegação de responsabilidade', ResponsibilityDelegation::query()->whereIn('scope_operation_id', $operationIds)],
                ['tem operação com medição registrada', Measurement::query()->whereIn('operation_id', $operationIds)],
                ['tem operação com comprovante de pagamento de medição', MeasurementPaymentReceiptEvidence::query()->whereIn(
                    'measurement_payment_id',
                    MeasurementPayment::query()->select('id')->whereIn('operation_id', $operationIds),
                )],
                ['tem evento registrado numa série de obrigações', ObligationAnchorEvent::query()->whereIn(
                    'obligation_series_id',
                    ObligationSeries::query()->select('id')->where('emission_id', $emissionId),
                )],
            ],
        );
    }

    /**
     * O contrato já foi lido por um ciclo do Quadro de Vendas?
     *
     * Um contrato que aparece numa linha, num movimento ou numa divergência
     * congelados é parte de uma competência conferida. Excluí-lo -- ou restaurá-lo
     * -- faria a venda sumir ou reaparecer em todas as competências, sem
     * distrato e sem movimento que explique a diferença.
     */
    public function isContractFrozen(?int $contractId): bool
    {
        if ($contractId === null) {
            return false;
        }

        return $this->presentReasons([
            ['congelado', SalesBoardCycleLine::query()->where('contract_id', $contractId)],
            ['congelado', SalesBoardCycleMovement::query()->where('contract_id', $contractId)],
            ['congelado', SalesBoardBuilderDivergence::query()->where('contract_id', $contractId)],
        ]) !== [];
    }

    /**
     * @param  list<int>|Builder<ConstructionUnit>  $unitIds
     * @return list<array{0: string, 1: Builder<*>}>
     */
    private function unitProbes(array|Builder $unitIds): array
    {
        return [
            ['tem histórico de valores', ConstructionUnitValue::query()->whereIn('construction_unit_id', $unitIds)],
            ['tem permuta registrada', ConstructionUnitExchange::query()->whereIn('construction_unit_id', $unitIds)],
            ['tem baixa registrada', ConstructionUnitRetirement::query()->whereIn('construction_unit_id', $unitIds)],
            ['já compõe a posição congelada de um ciclo do Quadro de Vendas', SalesBoardCycleLine::query()->whereIn('construction_unit_id', $unitIds)],
            ['já compõe a posição congelada de um ciclo do Quadro de Vendas', SalesBoardCycleMovement::query()->whereIn('construction_unit_id', $unitIds)],
            ['já compõe a posição congelada de um ciclo do Quadro de Vendas', SalesBoardBuilderDivergence::query()->whereIn('construction_unit_id', $unitIds)],
        ];
    }

    /**
     * O que o Quadro de Vendas já gravou sobre as obras.
     *
     * @param  list<int>|Builder<Construction>  $constructionIds
     * @return list<array{0: string, 1: Builder<*>}>
     */
    private function salesBoardProbes(array|Builder $constructionIds): array
    {
        return [
            ['tem Quadro de Vendas registrado', SalesBoard::query()->whereIn('construction_id', $constructionIds)],
            ['tem ciclo do Quadro de Vendas', SalesBoardCycle::query()->whereIn('construction_id', $constructionIds)],
            ['está na automação do Quadro de Vendas', SalesBoardAutomationTarget::query()->whereIn('construction_id', $constructionIds)],
            ['está numa homologação do rollout do Quadro de Vendas', SalesBoardRolloutHomologationConstruction::query()->whereIn('construction_id', $constructionIds)],
        ];
    }

    /**
     * O cadastro comercial das obras e das unidades delas.
     *
     * O contrato é procurado pelos dois lados: pela obra gravada nele e pela
     * unidade. Uma unidade movida antes desta guarda existir deixou contratos
     * com a obra antiga, e a cascata sobre as unidades esbarraria neles.
     *
     * @param  list<int>|Builder<Construction>  $constructionIds
     * @return list<array{0: string, 1: Builder<*>}>
     */
    private function constructionSourceProbes(array|Builder $constructionIds): array
    {
        $unitIds = ConstructionUnit::query()->select('id')->whereIn('construction_id', $constructionIds);

        return [
            ['tem contrato registrado, inclusive excluído', Contract::withTrashed()->whereIn('construction_id', $constructionIds)],
            ['tem contrato registrado, inclusive excluído', Contract::withTrashed()->whereIn('construction_unit_id', $unitIds)],
            ['tem política comercial de desconto', SalesDiscountPolicy::query()->whereIn('construction_id', $constructionIds)],
            ...array_map(
                fn (array $probe): array => ['tem unidade que '.$probe[0], $probe[1]],
                $this->unitProbes($unitIds),
            ),
        ];
    }

    /**
     * Os motivos cuja consulta encontra ao menos uma linha, na ordem em que
     * foram listados e sem repetição.
     *
     * Todas as consultas vão num único `select` sem `from` -- válido no MySQL e
     * no SQLite --, cada uma como `(select 1 ... limit 1)`.
     *
     * @param  list<array{0: string, 1: Builder<*>}>  ...$probeLists
     * @return list<string>
     */
    private function presentReasons(array ...$probeLists): array
    {
        $probes = array_merge(...$probeLists);
        $select = DB::query();

        foreach ($probes as $index => [, $query]) {
            $select->selectSub($query->toBase()->selectRaw('1')->limit(1), 'probe_'.$index);
        }

        $row = (array) $select->first();
        $reasons = [];

        foreach ($probes as $index => [$reason]) {
            if (($row['probe_'.$index] ?? null) !== null) {
                $reasons[$reason] = true;
            }
        }

        return array_keys($reasons);
    }
}
