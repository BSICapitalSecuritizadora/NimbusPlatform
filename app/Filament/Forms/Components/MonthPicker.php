<?php

namespace App\Filament\Forms\Components;

use Carbon\CarbonImmutable;
use Filament\Forms\Components\Concerns\HasPlaceholder;
use Filament\Forms\Components\Field;

/**
 * Campo de competência mensal (mês/ano), exibido como `mm/aaaa` e selecionado numa grade
 * de meses — sem obrigar o usuário a escolher um dia que a regra de negócio não usa.
 *
 * O estado gravado é a string `Y-m` (ex.: `2026-09`). Estados herdados com dia (`Y-m-d`,
 * vindos de URLs antigas ou do cockpit) continuam legíveis: valem pelo mês a que pertencem
 * e não são reescritos na hidratação. {@see self::parseMonth()} é o único ponto que
 * interpreta esse estado.
 *
 * O popup é posicionado com `position: fixed` pelo script registrado no painel
 * (`filament.forms.month-picker-script`), para não ser recortado por contêineres com
 * `overflow` — como o corpo rolável do painel de filtros.
 */
class MonthPicker extends Field
{
    use HasPlaceholder;

    public const DEFAULT_RANGE_MESSAGE = 'A competência final deve ser igual ou posterior à competência inicial.';

    protected string $view = 'filament.forms.components.month-picker';

    protected ?string $notBeforeField = null;

    protected ?string $notAfterField = null;

    protected string $rangeMessage = self::DEFAULT_RANGE_MESSAGE;

    protected function setUp(): void
    {
        parent::setUp();

        $this->placeholder('mm/aaaa');
    }

    /**
     * Converte o estado do campo no primeiro dia da competência, ou `null` quando vazio ou
     * ilegível. Aceita `Y-m` e, por compatibilidade, qualquer data `Y-m-d`.
     */
    public static function parseMonth(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})(?:-(\d{2}))?$/', trim($value), $matches)) {
            return null;
        }

        $year = (int) $matches[1];
        $month = (int) $matches[2];
        $day = isset($matches[3]) ? (int) $matches[3] : 1;

        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return CarbonImmutable::create($year, $month, 1)->startOfDay();
    }

    /**
     * Impede, na grade, meses anteriores ao do campo irmão informado e exibe a mensagem de
     * intervalo inválido quando o estado já chega invertido.
     */
    public function notBeforeField(string $fieldName, ?string $message = null): static
    {
        $this->notBeforeField = $fieldName;

        if (filled($message)) {
            $this->rangeMessage = $message;
        }

        return $this;
    }

    /**
     * Impede, na grade, meses posteriores ao do campo irmão informado.
     */
    public function notAfterField(string $fieldName): static
    {
        $this->notAfterField = $fieldName;

        return $this;
    }

    public function getNotBeforeStatePath(): ?string
    {
        return filled($this->notBeforeField) ? $this->resolveRelativeStatePath($this->notBeforeField) : null;
    }

    public function getNotAfterStatePath(): ?string
    {
        return filled($this->notAfterField) ? $this->resolveRelativeStatePath($this->notAfterField) : null;
    }

    public function getRangeMessage(): string
    {
        return $this->rangeMessage;
    }
}
