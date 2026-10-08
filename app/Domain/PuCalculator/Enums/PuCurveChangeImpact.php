<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

/**
 * O que uma mudança de insumo faz com uma versão de curva já gerada.
 *
 * - `NoCurveImpact`: nada que a engine use mudou (descrição, rótulo, ordem de
 *   exibição). A versão continua valendo e avançando;
 * - `ExtensionSafe`: dado de mercado novo (CDI realizado divulgado). É assim que a
 *   curva oficial avança: a extensão diária anexa os dias novos;
 * - `FutureVersionRequired`: mudança contratual que só vale DEPOIS do último dia
 *   gravado da versão. Nada gravado mudou, mas a versão não pode absorver a
 *   mudança: ela avança no máximo até a véspera da data afetada, e uma versão
 *   nova, homologada, precisa existir antes que a mudança chegue ao PU oficial;
 * - `HistoricalReprocessRequired`: a mudança alcança dias já gravados. A versão
 *   fica imutável, a extensão fica suspensa, e só uma versão nova reprocessada e
 *   homologada a substitui.
 */
enum PuCurveChangeImpact: string
{
    case NoCurveImpact = 'no_curve_impact';
    case ExtensionSafe = 'extension_safe';
    case FutureVersionRequired = 'future_version_required';
    case HistoricalReprocessRequired = 'historical_reprocess_required';

    public function label(): string
    {
        return match ($this) {
            self::NoCurveImpact => 'Sem impacto na curva',
            self::ExtensionSafe => 'Extensão segura',
            self::FutureVersionRequired => 'Nova versão necessária antes da data afetada',
            self::HistoricalReprocessRequired => 'Reprocessamento do passado necessário',
        };
    }

    /**
     * A versão ainda pode receber dias novos -- no caso de mudança futura, só até a
     * véspera da data afetada.
     */
    public function allowsExtension(): bool
    {
        return $this !== self::HistoricalReprocessRequired;
    }

    /**
     * A mudança só chega ao PU oficial por uma versão nova.
     */
    public function requiresNewVersion(): bool
    {
        return $this === self::FutureVersionRequired || $this === self::HistoricalReprocessRequired;
    }

    /**
     * Dias já gravados deixaram de ser reproduzíveis.
     */
    public function requiresReprocessing(): bool
    {
        return $this === self::HistoricalReprocessRequired;
    }

    /**
     * A versão nova só vira oficial pela homologação (Fase 2): nenhuma rotina a
     * publica.
     */
    public function requiresHomologation(): bool
    {
        return $this->requiresNewVersion();
    }

    /**
     * Gravidade para combinar várias mudanças: vale a mais restritiva.
     */
    public function severity(): int
    {
        return match ($this) {
            self::NoCurveImpact => 0,
            self::ExtensionSafe => 1,
            self::FutureVersionRequired => 2,
            self::HistoricalReprocessRequired => 3,
        };
    }
}
