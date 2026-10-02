<?php

declare(strict_types=1);

namespace App\Support\SalesBoards;

use App\Models\Construction;
use App\Models\Contract;
use App\Models\ContractInstallment;
use App\Services\SalesBoards\RegisteredCompetenceIndex;
use App\Support\Money\IntegerMoney;
use DateTimeInterface;
use Filament\Notifications\Notification;

/**
 * O aviso dos formulários da fonte quando o fato digitado alcança competência
 * já registrada no Quadro de Vendas.
 *
 * Só avisa. O fato atrasado tem caminho governado -- movimento extemporâneo na
 * competência seguinte, ou a retificação da última publicada --, e bloquear a
 * entrada impediria correções legítimas. O texto vem de
 * {@see RegisteredCompetenceIndex::noticeFor()}, o mesmo das importações; aqui
 * se decide só quais datas do formulário pesam, comparando com o que estava
 * gravado. Duas consultas por pergunta, sobre um empreendimento.
 */
final class SourceEntryCompetenceNotice
{
    /**
     * Contrato: a data da venda (nova ou alterada), o valor da venda alterado
     * num contrato já gravado e a data do distrato.
     */
    public static function forContract(
        mixed $constructionId,
        mixed $saleDate,
        mixed $saleValue,
        mixed $cancellationDate,
        ?Contract $original = null,
    ): ?string {
        $constructionId = self::id($constructionId);

        if ($constructionId === null) {
            return null;
        }

        $index = RegisteredCompetenceIndex::forConstructions([$constructionId]);
        $saleDay = self::day($saleDate);
        $originalSaleDay = $original?->sale_date?->toDateString();
        $notices = [];

        if (($original === null) || ($saleDay !== $originalSaleDay)) {
            $notices[] = $index->noticeFor($constructionId, RegisteredCompetenceIndex::SUBJECT_SALE, $saleDay, $original === null ? null : $originalSaleDay);
        } elseif (IntegerMoney::cents($saleValue) !== IntegerMoney::cents($original->sale_value)) {
            $notices[] = $index->noticeFor($constructionId, RegisteredCompetenceIndex::SUBJECT_SALE_VALUE, $saleDay);
        }

        $cancellationDay = self::day($cancellationDate);
        $originalCancellationDay = $original?->cancellation_date?->toDateString();

        if ((($cancellationDay !== null) || ($originalCancellationDay !== null)) && ($cancellationDay !== $originalCancellationDay)) {
            $notices[] = $index->noticeFor($constructionId, RegisteredCompetenceIndex::SUBJECT_CANCELLATION, $cancellationDay, $originalCancellationDay);
        }

        return self::join($notices);
    }

    /**
     * Parcela: o pagamento e o cancelamento -- os dois decidem a quitação.
     */
    public static function forInstallment(
        mixed $constructionId,
        mixed $paymentDate,
        mixed $cancellationDate,
        ?ContractInstallment $original = null,
    ): ?string {
        $constructionId = self::id($constructionId);

        if ($constructionId === null) {
            return null;
        }

        $index = RegisteredCompetenceIndex::forConstructions([$constructionId]);
        $notices = [];

        $paymentDay = self::day($paymentDate);
        $originalPaymentDay = self::day($original?->payment_date);

        if ((($paymentDay !== null) || ($originalPaymentDay !== null)) && ($paymentDay !== $originalPaymentDay)) {
            $notices[] = $index->noticeFor($constructionId, RegisteredCompetenceIndex::SUBJECT_PAYMENT, $paymentDay, $originalPaymentDay);
        }

        $cancellationDay = self::day($cancellationDate);
        $originalCancellationDay = self::day($original?->cancellation_date);

        if ((($cancellationDay !== null) || ($originalCancellationDay !== null)) && ($cancellationDay !== $originalCancellationDay)) {
            $notices[] = $index->noticeFor($constructionId, RegisteredCompetenceIndex::SUBJECT_INSTALLMENT_CANCELLATION, $cancellationDay, $originalCancellationDay);
        }

        return self::join($notices);
    }

    /**
     * Cancelamento em lote das parcelas em aberto que a planilha não trouxe: a
     * data escolhida na conferência, contra as competências registradas de cada
     * empreendimento alcançado.
     *
     * É a mesma mudança que o formulário da parcela avisa quando o cancelamento
     * é digitado à mão, só que em lote e sobre várias obras. Para o quadro
     * registrado à mão, este aviso é o único sinal de que ele precisa ser
     * revisto em "Nova Atualização". Com mais de uma obra alcançada, cada aviso
     * leva o nome do empreendimento. Duas consultas para o índice e, só com mais
     * de uma obra avisada, uma para os nomes.
     *
     * @param  list<int>  $constructionIds
     */
    public static function forAbsentInstallmentCancellation(array $constructionIds, mixed $cancellationDate): ?string
    {
        $day = self::day($cancellationDate);
        $constructionIds = array_values(array_unique(array_filter(array_map(self::id(...), $constructionIds))));

        if (($day === null) || ($constructionIds === [])) {
            return null;
        }

        $index = RegisteredCompetenceIndex::forConstructions($constructionIds);
        $notices = [];

        foreach ($constructionIds as $constructionId) {
            $notice = $index->noticeFor($constructionId, RegisteredCompetenceIndex::SUBJECT_INSTALLMENT_CANCELLATION, $day);

            if ($notice !== null) {
                $notices[$constructionId] = $notice;
            }
        }

        if (count($notices) <= 1) {
            return self::join(array_values($notices));
        }

        $names = Construction::query()
            ->whereKey(array_keys($notices))
            ->pluck('development_name', 'id');

        return self::join(array_map(
            fn (int $constructionId, string $notice): string => sprintf('%s: %s', (string) ($names[$constructionId] ?? "Empreendimento #{$constructionId}"), $notice),
            array_keys($notices),
            $notices,
        ));
    }

    /**
     * Valor de unidade: a vigência.
     */
    public static function forUnitValue(mixed $constructionId, mixed $effectiveFrom): ?string
    {
        $constructionId = self::id($constructionId);
        $day = self::day($effectiveFrom);

        if (($constructionId === null) || ($day === null)) {
            return null;
        }

        return RegisteredCompetenceIndex::forConstructions([$constructionId])
            ->noticeFor($constructionId, RegisteredCompetenceIndex::SUBJECT_UNIT_VALUE, $day);
    }

    /**
     * A notificação persistente depois de salvar: o formulário avisou antes, e
     * quem salvou precisa saber por onde o fato vai entrar até fechá-la.
     */
    public static function notify(?string $notice): void
    {
        if ($notice === null) {
            return;
        }

        Notification::make()
            ->title('Fato em competência já registrada no Quadro de Vendas')
            ->body($notice)
            ->warning()
            ->persistent()
            ->send();
    }

    /**
     * @param  list<string|null>  $notices
     */
    private static function join(array $notices): ?string
    {
        $notices = array_values(array_unique(array_filter($notices, fn (?string $notice): bool => filled($notice))));

        return $notices === [] ? null : implode(' ', $notices);
    }

    private static function id(mixed $value): ?int
    {
        return (is_numeric($value) && ((int) $value > 0)) ? (int) $value : null;
    }

    /**
     * O dia (`Y-m-d`) de um valor de formulário ou de model: o DatePicker não
     * nativo guarda a hora junto.
     */
    private static function day(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (blank($value) || (preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $value) !== 1)) {
            return null;
        }

        return substr((string) $value, 0, 10);
    }
}
