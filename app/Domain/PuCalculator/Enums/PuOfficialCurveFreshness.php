<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

/**
 * Situação da curva oficial frente ao índice realizado disponível.
 *
 * "Atual" não significa "chega até hoje": o CDI do dia só é divulgado depois, e
 * a curva realizada termina antes do primeiro dia cuja observação ainda não
 * podia existir. Atrasada é a curva que deixou de incorporar índice que JÁ existe.
 */
enum PuOfficialCurveFreshness: string
{
    /** Não há curva homologada: nada é oficial, e nada vira oficial sozinho. */
    case NoOfficialCurve = 'no_official_curve';

    /** A curva oficial realizada já cobre o período inteiro da curva. */
    case Complete = 'complete';

    /** Incorporou todo o índice realizado disponível; o próximo ainda não era esperado. */
    case Current = 'current';

    /** Há índice realizado novo que a curva oficial ainda não incorporou. */
    case Stale = 'stale';

    /** Falta uma observação exigida: buraco no histórico ou publicação atrasada. */
    case MissingIndex = 'missing_index';

    /** A extensão da curva oficial tentou e falhou (pré-requisito ou erro de cálculo). */
    case ExtensionFailed = 'extension_failed';

    /** O passado da curva oficial mudou (índice corrigido, parâmetro, evento): a extensão está suspensa. */
    case ReprocessingRequired = 'reprocessing_required';

    /** Indexador sem extensão diária por índice realizado (prefixado, IPCA fora de operação). */
    case NotTracked = 'not_tracked';

    public function label(): string
    {
        return match ($this) {
            self::NoOfficialCurve => 'Sem curva oficial',
            self::Complete => 'Completa até o vencimento',
            self::Current => 'Atualizada',
            self::Stale => 'Atrasada: há índice realizado não incorporado',
            self::MissingIndex => 'Índice exigido ausente',
            self::ExtensionFailed => 'Extensão diária falhou',
            self::ReprocessingRequired => 'Reprocessamento necessário',
            self::NotTracked => 'Sem acompanhamento diário',
        };
    }

    /**
     * A curva oficial responde por todo o índice realizado que já existe.
     */
    public function isUpToDate(): bool
    {
        return in_array($this, [self::Complete, self::Current, self::NotTracked], true);
    }
}
