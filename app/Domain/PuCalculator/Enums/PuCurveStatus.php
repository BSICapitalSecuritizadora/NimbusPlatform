<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

enum PuCurveStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Generated = 'generated';
    case Validated = 'validated';
    case Homologated = 'homologated';
    case Divergent = 'divergent';
    case Error = 'error';
    case Obsolete = 'obsolete';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendente',
            self::Processing => 'Processando',
            self::Generated => 'Gerada',
            self::Validated => 'Validada',
            self::Homologated => 'Homologada',
            self::Divergent => 'Divergente',
            self::Error => 'Erro',
            self::Obsolete => 'Obsoleta',
        };
    }

    /**
     * Cor do badge no Filament.
     */
    public function color(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Processing => 'info',
            self::Generated => 'warning',
            self::Validated => 'success',
            self::Homologated => 'success',
            self::Divergent => 'danger',
            self::Error => 'danger',
            self::Obsolete => 'gray',
        };
    }

    /**
     * Estados protegidos nao podem ser sobrescritos sem confirmacao explicita.
     */
    public function isProtected(): bool
    {
        return $this === self::Homologated;
    }

    public function isActive(): bool
    {
        return ! in_array($this, [self::Obsolete, self::Error], true);
    }

    /**
     * Cálculo concluído e utilizável: o que pode ser a versão de trabalho vigente
     * (`EmissionPuCurveVersion::scopeCurrent()`). `processing`, `pending`, `error` e
     * `obsolete` nunca entram, por mais novos que sejam: uma tentativa que falhou
     * não esconde a última versão utilizável.
     *
     * Utilizável não é oficial: oficial é só `Homologated` ({@see self::isOfficial()}).
     *
     * @return list<self>
     */
    public static function workable(): array
    {
        return [self::Generated, self::Validated, self::Divergent, self::Homologated];
    }

    public function isWorkable(): bool
    {
        return in_array($this, self::workable(), true);
    }

    /**
     * Único estado que torna uma curva fonte oficial de PU.
     */
    public function isOfficial(): bool
    {
        return $this === self::Homologated;
    }

    /**
     * Estados de onde uma homologação explícita pode partir. `Validated` é o
     * caminho estrito; `Generated` (checker diferente do maker) e `Divergent`
     * (com justificativa) são as exceções que `HomologatePuCurve` controla.
     */
    public function canBeHomologated(): bool
    {
        return in_array($this, [self::Generated, self::Validated, self::Divergent], true);
    }

    /**
     * Invalidar só vale para cálculo concluído. `Processing` fica de fora: a
     * geração ainda é dona da versão e a concluiria por cima da invalidação.
     */
    public function canBeInvalidated(): bool
    {
        return in_array($this, [self::Generated, self::Validated, self::Divergent, self::Homologated], true);
    }

    /**
     * Uma validação contra planilha só grava resultado em cálculo concluído e
     * ainda vivo. Na homologada fica só o resumo; o status não muda.
     */
    public function acceptsValidationResult(): bool
    {
        return $this->isWorkable();
    }

    /**
     * Grafo de transições do ciclo de vida operacional. `Obsolete` é terminal: a
     * invalidação e a substituição preservam a versão para auditoria e nada a
     * traz de volta.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Processing, self::Error, self::Obsolete],
            self::Processing => [self::Generated, self::Error, self::Obsolete],
            self::Generated => [self::Validated, self::Divergent, self::Homologated, self::Error, self::Obsolete],
            self::Validated, self::Divergent => [self::Validated, self::Divergent, self::Homologated, self::Obsolete],
            self::Homologated, self::Error => [self::Obsolete],
            self::Obsolete => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
