<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\Exceptions\SalesBoardRolloutException;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\SalesBoard;
use App\Models\SalesBoardPublication;
use App\Support\SalesBoards\SalesBoardWriteContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Impede que a posição de uma competência automatizada seja escrita à mão.
 *
 * A partir do rollout existe um risco que não existia antes: uma Emissão passa a
 * ter a posição produzida pelo ciclo mensal, e alguém continua registrando o
 * quadro manualmente. As duas escritas disputariam a mesma competência, e a
 * publicação da Fase E seria recusada pelo conflito -- ou, pior, a posição
 * manual chegaria primeiro e o ciclo inteiro viraria trabalho perdido.
 *
 * Três regras, e todas no model, não na tela:
 *
 * 1. **competência automatizada não recebe escrita manual.** Vale a partir da
 *    competência inicial; o histórico anterior ao corte continua editável, que
 *    é o que permite manutenção do passado;
 * 2. **quadro publicado não é editado nem apagado**, em nenhum modo. Ele
 *    atravessou validação da construtora, análise da Gestão e aprovação; alterá-lo
 *    por fora mudaria Garantias e Relatório sem passar por nada disso. A única
 *    escrita aceita é a republicação do próprio quadro, aberta pela aprovação de
 *    uma retificação ({@see SalesBoardWriteContext::asRepublication()}) -- só no
 *    update, nunca na exclusão, que continua recusada sempre;
 * 3. **uma posição só por empreendimento e competência.** O quadro fica sob a
 *    Emissão atual do empreendimento, e não existe um segundo quadro do mesmo
 *    empreendimento no mesmo mês, em Emissão nenhuma. O
 *    {@see SalesBoardPositionReader} lê a posição por empreendimento, sem olhar
 *    a Emissão: um quadro fora da Emissão do empreendimento seria somado pelas
 *    duas, e dois quadros no mesmo mês seriam lidos por um desempate que
 *    ninguém escolheu. Vale também para a publicação, e só na criação ou na
 *    troca de identidade (Emissão, empreendimento ou competência): corrigir os
 *    valores de um registro antigo que já nasceu fora da regra continua livre.
 *
 * A regra 3 não vira constraint de banco de propósito. A unique de
 * `sales_boards` inclui a Emissão, e trocá-la por (empreendimento, mês) faria o
 * `migrate --force` do startup falhar se a produção já tiver uma duplicata --
 * e sem a migração a fila e o agendador não sobem. Com o quadro sempre na
 * Emissão do empreendimento, toda escrita nova do mesmo empreendimento cai na
 * mesma Emissão, e aí a unique existente já fecha a corrida entre duas
 * gravações simultâneas. A anomalia anterior ao guard é diagnosticada por
 * `sales-boards:position-drift` e corrigida pelo model.
 *
 * A tela também barra, mas a tela não é segurança: comando, job, tinker e
 * importação passam por aqui do mesmo jeito. Para a tela perguntar antes, sem
 * duplicar a regra, existem {@see self::isPublished()} e
 * {@see self::manualWriteRefusal()} -- leituras que respondem exatamente o que
 * a escrita responderia.
 */
class SalesBoardWriteGuard
{
    public function __construct(
        private readonly SalesBoardWriteContext $context,
    ) {}

    /**
     * Uma escrita está prestes a acontecer.
     */
    public function assertCanWrite(SalesBoard $salesBoard): void
    {
        /**
         * A republicação vale só para o quadro que ela abriu e só no update: é a
         * aprovação de uma retificação reescrevendo os valores do quadro
         * publicado daquela competência, com motivo e autor no histórico de
         * versões.
         */
        $republishing = $salesBoard->exists && $this->context->isRepublishing((int) $salesBoard->getKey());

        if ($salesBoard->exists && ! $republishing) {
            $this->assertNotPublished($salesBoard);
        }

        /**
         * A publicação é a escrita legítima do modo automatizado. Ela abre o
         * contexto em volta da própria criação, e nada mais no sistema o abre --
         * além da republicação, que reescreve o quadro que a publicação criou.
         *
         * A ordem importa para quem lê a recusa: a competência automatizada
         * responde antes da posição única, porque é ela que diz ao operador que
         * aquele mês não se registra à mão.
         */
        if (! $this->context->isPublishing() && ! $republishing) {
            $this->assertNotAutomatedCompetence($salesBoard);
        }

        if ($this->changesPositionIdentity($salesBoard)) {
            $this->assertSinglePositionOfTheConstruction($salesBoard);
        }
    }

    /**
     * A exclusão do quadro publicado é recusada sempre -- inclusive dentro da
     * republicação, que só atualiza.
     */
    public function assertCanDelete(SalesBoard $salesBoard): void
    {
        $this->assertNotPublished($salesBoard);
    }

    /**
     * O quadro passou pela governança do ciclo? Basta uma publicação.
     */
    public function isPublished(SalesBoard $salesBoard): bool
    {
        return $salesBoard->exists
            && SalesBoardPublication::query()
                ->where('sales_board_id', $salesBoard->getKey())
                ->exists();
    }

    /**
     * Por que o registro manual da competência seria recusado, ou `null`.
     *
     * O candidato é montado como a tela de "Nova Atualização" o monta: o quadro
     * que já existe para (obra, mês) -- a mesma chave do leitor e da
     * publicação --, ou um quadro novo com a Emissão, a obra e o mês
     * informados. A resposta é a do próprio {@see self::assertCanWrite()},
     * capturada: a tela diz no campo da competência o que a gravação diria no
     * fim, sem uma segunda cópia da regra -- inclusive a posição única do
     * empreendimento (regra 3), que só pesa sobre o quadro novo.
     *
     * Só leitura. Fora de transação a leitura das Emissões e do empreendimento
     * não trava nada, e a tela não abre transação para perguntar.
     */
    public function manualWriteRefusal(mixed $emissionId, mixed $constructionId, mixed $referenceMonth): ?string
    {
        $month = SalesBoard::normalizeReferenceMonth($referenceMonth);

        if (blank($emissionId) || blank($constructionId) || ($month === null)) {
            return null;
        }

        $candidate = SalesBoard::query()
            ->where('construction_id', $constructionId)
            ->whereDate('reference_month', $month)
            ->orderBy('id')
            ->first()
            ?? new SalesBoard([
                'emission_id' => $emissionId,
                'construction_id' => $constructionId,
                'reference_month' => $month,
            ]);

        try {
            $this->assertCanWrite($candidate);
        } catch (SalesBoardRolloutException $exception) {
            return $exception->getMessage();
        }

        return null;
    }

    /**
     * A escrita cria uma posição ou a muda de lugar?
     *
     * Só criação e troca de Emissão, empreendimento ou competência passam pela
     * regra 3. Corrigir os valores de um quadro que já existe não cria posição
     * nova -- e é o que permite manter um registro legado que nasceu antes do
     * guard sem antes ter de decidir o destino dele.
     *
     * A competência é comparada pelo mês, e não pelo dia gravado. O `saving` do
     * model normaliza a competência para o dia 01 antes do guard; num quadro que
     * uma carga por fora deixou com outro dia, essa normalização sujaria
     * `reference_month` em toda manutenção de valores, e ela viraria uma troca
     * de competência que nunca aconteceu -- o mesmo mês, lido pelo leitor da
     * posição como o mesmo mês.
     */
    private function changesPositionIdentity(SalesBoard $salesBoard): bool
    {
        if ((! $salesBoard->exists) || $salesBoard->isDirty(['emission_id', 'construction_id'])) {
            return true;
        }

        return $salesBoard->isDirty('reference_month')
            && (SalesBoard::normalizeReferenceMonth($salesBoard->getOriginal('reference_month'))
                !== SalesBoard::normalizeReferenceMonth($salesBoard->reference_month));
    }

    /**
     * Regra 3: o quadro fica na Emissão atual do empreendimento, e o
     * empreendimento não tem outro quadro no mesmo mês.
     *
     * O empreendimento é lido em modo compartilhado dentro de transação -- e o
     * {@see SalesBoard} grava sempre dentro de uma --, como as Emissões em
     * {@see self::emissionsClaiming()}: uma troca de Emissão do empreendimento
     * em andamento termina antes desta conferência, e não depois dela.
     *
     * O outro quadro é procurado pelo mês inteiro, e não pelo dia gravado: uma
     * carga feita por fora do model pode ter deixado o dia diferente de 01, e o
     * leitor da posição trata os dois como o mesmo mês.
     */
    private function assertSinglePositionOfTheConstruction(SalesBoard $salesBoard): void
    {
        $referenceMonth = SalesBoard::normalizeReferenceMonth($salesBoard->reference_month);

        if (($referenceMonth === null) || blank($salesBoard->construction_id) || blank($salesBoard->emission_id)) {
            return;
        }

        $query = Construction::query()->whereKey((int) $salesBoard->construction_id);

        if ($query->getConnection()->transactionLevel() > 0) {
            $query->sharedLock();
        }

        $construction = $query->first(['id', 'emission_id', 'development_name']);

        /*
         * Empreendimento inexistente fica para a FK recusar: não há posição de
         * ninguém a proteger.
         */
        if (! $construction instanceof Construction) {
            return;
        }

        $month = CarbonImmutable::parse($referenceMonth);
        $constructionName = (string) ($construction->development_name ?? '—');

        if ((int) $construction->emission_id !== (int) $salesBoard->emission_id) {
            throw SalesBoardRolloutException::boardOutsideConstructionEmission(
                $constructionName,
                $month->format('m/Y'),
                $this->emissionName((int) $salesBoard->emission_id),
                $this->emissionName((int) $construction->emission_id),
            );
        }

        $existing = SalesBoard::query()
            ->where('construction_id', $construction->getKey())
            ->whereDate('reference_month', '>=', $month->toDateString())
            ->whereDate('reference_month', '<=', $month->endOfMonth()->toDateString())
            ->when($salesBoard->exists, fn (Builder $query): Builder => $query->whereKeyNot($salesBoard->getKey()))
            ->orderBy('id')
            ->first(['id', 'emission_id']);

        if ($existing instanceof SalesBoard) {
            throw SalesBoardRolloutException::competenceAlreadyPositioned(
                $constructionName,
                $month->format('m/Y'),
                $this->emissionName((int) $existing->emission_id),
            );
        }
    }

    private function emissionName(int $emissionId): string
    {
        $name = Emission::query()->whereKey($emissionId)->value('name');

        return filled($name) ? (string) $name : '#'.$emissionId;
    }

    /**
     * Um quadro que passou pela governança do ciclo é imutável.
     */
    private function assertNotPublished(SalesBoard $salesBoard): void
    {
        if ($this->isPublished($salesBoard)) {
            throw SalesBoardRolloutException::publishedBoardIsImmutable();
        }
    }

    /**
     * A competência já pertence ao motor automático?
     *
     * A competência é normalizada antes de comparar -- o model normaliza no
     * `saving`, mas o guard roda antes e precisa comparar a mesma coisa.
     */
    private function assertNotAutomatedCompetence(SalesBoard $salesBoard): void
    {
        $referenceMonth = SalesBoard::normalizeReferenceMonth($salesBoard->reference_month);

        if ($referenceMonth === null) {
            return;
        }

        $month = CarbonImmutable::parse($referenceMonth);

        $automated = $this->emissionsClaiming($salesBoard)
            ->first(fn (Emission $emission): bool => $emission->automationCovers($month));

        if (! $automated instanceof Emission) {
            return;
        }

        throw SalesBoardRolloutException::manualWriteBlocked(
            (string) ($salesBoard->construction?->development_name ?? $automated->name ?? '—'),
            $month->format('m/Y'),
        );
    }

    /**
     * As Emissões que respondem pela posição deste quadro: a gravada nele e a
     * atual do empreendimento.
     *
     * Costumam ser a mesma -- a tela e a carga inicial gravam a Emissão do
     * empreendimento --, mas o {@see SalesBoardPositionReader} lê a posição por
     * empreendimento, sem olhar a Emissão. Um quadro gravado por fora com a
     * Emissão errada seria lido como a posição de um empreendimento
     * automatizado, e olhar só a Emissão do quadro deixaria essa escrita passar.
     *
     * Dentro de transação -- e o {@see SalesBoard} grava sempre dentro de uma --
     * a leitura trava as Emissões em modo compartilhado até o commit. É o que
     * serializa o registro manual com a ativação da automação, que trava a
     * Emissão com `FOR UPDATE`, confere o conflito com o legado e só depois
     * refaz a derivação inteira. Uma leitura simples não esperaria esse lock:
     * enxergaria o modo "legado" ainda não commitado e deixaria o quadro manual
     * da competência inicial nascer no meio da ativação. Com o lock, ou o quadro
     * commita antes e a ativação o encontra como conflito, ou o guard espera a
     * ativação terminar e lê o modo já automatizado. A ordem é a do id, a mesma
     * em qualquer escrita, para duas gravações nunca travarem as mesmas
     * Emissões em ordem inversa.
     *
     * @return Collection<int, Emission>
     */
    private function emissionsClaiming(SalesBoard $salesBoard): Collection
    {
        $constructionEmissionId = $salesBoard->construction_id === null
            ? null
            : Construction::query()->whereKey($salesBoard->construction_id)->value('emission_id');

        $emissionIds = collect([$salesBoard->emission_id, $constructionEmissionId])
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($emissionIds === []) {
            return collect();
        }

        $query = Emission::query()->whereKey($emissionIds)->orderBy('id');

        if ($query->getConnection()->transactionLevel() > 0) {
            $query->sharedLock();
        }

        return $query->get();
    }
}
