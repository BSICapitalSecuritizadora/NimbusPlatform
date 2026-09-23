<?php

namespace App\Filament\Support;

use Closure;
use Filament\Forms\Components\Select;

/**
 * Geometria compartilhada do dropdown dos filtros de "Emissão", "Empreendimento",
 * "Empresa de medição", "Operação", "Bloco" e "Tipo": o popup fica ancorado no trigger
 * que o abriu. Vale para todo filtro renderizado pelo select JS do Filament (pesquisável
 * ou `native(false)`); filtros com `<select>` nativo não sofrem o defeito e ficam fora.
 *
 * O select pesquisável do Filament v5 monta o painel (`.fi-dropdown-panel`) dentro do
 * próprio `.fi-select-input-ctn` e define `width` inline igual ao `offsetWidth` do
 * trigger, posicionando-o com Floating UI. Dentro dos filtros de tabela o painel é
 * posicionado com `position: fixed`, porque `.fi-ta-filters` declara
 * `.fi-fixed-positioning-context`; nesse modo o bloco contentor passa a ser a viewport
 * e qualquer `min-width: 100%` do tema resolve para 100vw, vencendo o `width` inline.
 *
 * Esta classe é o único ponto que decide como um filtro adere a esse comportamento; a
 * adesão é explícita, filtro a filtro, e nunca global. O comportamento correspondente
 * vive no bloco `.bsi-anchored-filter-dropdown` de `resources/css/filament/admin/theme.css`,
 * que devolve a largura do trigger, limita a altura e isola o scroll na lista de opções.
 * Nada aqui altera opções, query ou estado.
 */
class AnchoredFilterDropdown
{
    /**
     * Classe aplicada ao wrapper `.fi-fo-select` do campo, usada como escopo no tema.
     */
    public const DROPDOWN_CLASS = 'bsi-anchored-filter-dropdown';

    /**
     * Atributos extras para campos `Select` usados como filtro em formulários de página.
     *
     * @return array<string, string>
     */
    public static function fieldAttributes(): array
    {
        return ['class' => self::DROPDOWN_CLASS];
    }

    /**
     * Callback para `SelectFilter::modifyFormFieldUsing()`, que marca o campo montado
     * internamente pelo filtro sem tocar em suas opções ou na query aplicada.
     */
    public static function modifyFormField(): Closure
    {
        return static fn (Select $field): Select => $field->extraAttributes(self::fieldAttributes(), merge: true);
    }
}
