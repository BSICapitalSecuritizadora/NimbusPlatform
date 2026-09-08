<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\DTOs\SalesBoards\SalesBoardPublicationPayload;
use App\Exceptions\SalesBoardManagementReviewException;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleBaseline;
use App\Support\Money\IntegerMoney;
use Carbon\CarbonImmutable;

/**
 * Traduz uma versão congelada para o vocabulário do Quadro de Vendas legado.
 *
 * Camada explícita, e não um `array` montado dentro do serviço de publicação ou,
 * pior, dentro da tela. O mapeamento entre os nomes da V2 e os da tabela legada
 * é a única coisa nesta fase que precisa ser lida e conferida campo a campo, e
 * enterrá-lo num método de trinta linhas com transação em volta garantiria que
 * ninguém o conferisse.
 *
 * Não lê fonte viva. Contrato, parcela, unidade e permuta não são consultados
 * aqui: o que se publica é o que o baseline aprovado congelou. Recomputar na
 * publicação abriria a possibilidade de publicar um número diferente daquele que
 * a construtora validou e a Gestão aprovou -- que é exatamente o que toda esta
 * arquitetura existe para impedir.
 *
 * Recusa o que não pode ser publicado. Um baseline incompleto ou com unidade sem
 * classificação não tem projeção possível: `null` num balde significa "não foi
 * possível saber", e a coluna legada é `NOT NULL` -- escrever zero ali
 * transformaria ausência de dado em fato apurado.
 */
class SalesBoardPublicationProjection
{
    public function project(SalesBoardCycle $cycle, SalesBoardCycleBaseline $baseline): SalesBoardPublicationPayload
    {
        $this->assertPublishable($baseline);

        return new SalesBoardPublicationPayload(
            emissionId: (int) $cycle->emission_id,
            constructionId: (int) $cycle->construction_id,
            constructionName: $cycle->construction?->development_name,
            referenceMonth: CarbonImmutable::parse($cycle->reference_month->toDateString())->startOfMonth(),
            stockUnits: (int) $baseline->stock_units,
            stockValueCents: $this->cents($baseline->stock_value),
            financedUnits: (int) $baseline->financed_units,
            financedValueCents: $this->cents($baseline->financed_value),
            /**
             * A tradução: o balde `settled` da V2 é a coluna `paid_*` do quadro
             * legado. Mesmo conjunto de unidades, vocabulários diferentes.
             */
            paidUnits: (int) $baseline->settled_units,
            paidValueCents: $this->cents($baseline->settled_value),
            exchangedUnits: (int) $baseline->exchanged_units,
            exchangedValueCents: $this->cents($baseline->exchanged_value),
            /**
             * O total legado é a soma dos quatro baldes -- e é assim que o
             * próprio `SalesBoard` o recalcula ao salvar. Publicar
             * `units_total` do baseline daria o mesmo número quando não há
             * indeterminada, e um número diferente do persistido quando há;
             * como indeterminada bloqueia a publicação, a soma é a definição
             * correta nos dois lados.
             */
            totalUnits: (int) $baseline->stock_units
                + (int) $baseline->financed_units
                + (int) $baseline->settled_units
                + (int) $baseline->exchanged_units,
        );
    }

    /**
     * Defesa em profundidade: mesmo que uma inconsistência de banco tivesse
     * deixado passar um baseline incompleto, ele não vira quadro publicado.
     */
    private function assertPublishable(SalesBoardCycleBaseline $baseline): void
    {
        $undetermined = (int) $baseline->undetermined_units;

        if ($undetermined > 0) {
            throw SalesBoardManagementReviewException::baselineIncomplete($undetermined);
        }

        $missingValue = ($baseline->stock_value === null)
            || ($baseline->financed_value === null)
            || ($baseline->settled_value === null)
            || ($baseline->exchanged_value === null);

        if ($missingValue || ! $baseline->is_complete) {
            throw SalesBoardManagementReviewException::baselineIncomplete(0);
        }
    }

    private function cents(mixed $value): int
    {
        $cents = IntegerMoney::cents($value);

        if ($cents === null) {
            throw SalesBoardManagementReviewException::baselineIncomplete(0);
        }

        return $cents;
    }
}
