<?php

namespace App\Filament\Support;

use Filament\Resources\Pages\Page;
use Illuminate\Database\Eloquent\Model;

/**
 * Portão próprio para um RelationManager: só lê quem pode ver o registro dono.
 *
 * O Filament confere o acesso a um RelationManager em um ponto só -- o hydrate,
 * por `canViewForRecord()` -- e deixa três portas abertas:
 *
 * 1. sem policy no model relacionado, a resposta padrão é "permitido". As
 *    linhas, os movimentos e as versões de um ciclo, o histórico do quadro, as
 *    permutas e os valores da unidade e as políticas de desconto não têm
 *    policy, e respondiam a qualquer um que chegasse ao componente;
 * 2. o pedido `__lazyLoad` do Livewire pula o hydrate. Um snapshot de
 *    placeholder emitido antes da revogação -- assinado só com a chave da
 *    aplicação, sem vínculo com a sessão -- montava a tabela inteira depois
 *    dela;
 * 3. chamadas marcadas como renderless não renderizam nada: `getTableRecords`
 *    pedido logo depois do `__lazyLoad` devolvia a paginação inteira, com todas
 *    as colunas, em `effects.returns`.
 *
 * A regra é a da página que hospeda o RelationManager: quem pode ver o registro
 * dono pelo Resource da página pode ver o que pende dele -- e só quem pode. É
 * `canView`, e não `canEdit`, porque o RelationManager é leitura do registro
 * dono; as ações de escrita dentro dele continuam com autorização própria. A
 * regra padrão do Filament continua valendo por cima (a policy do model
 * relacionado, quando existe), o que mantém `contract-installments.view` nas
 * parcelas do contrato. Página que não é de Resource falha fechado.
 *
 * A regra é conferida em três pontos: em `canViewForRecord()`, que o hydrate e
 * as abas já usam; no hook de mount, que é o que roda no `__lazyLoad`; e no
 * hook de rendering, em qualquer render. Os dois hooks respondem 403.
 *
 * Quem usa o trait não declara `canViewForRecord()` nem hooks de mount ou de
 * rendering próprios: o método da classe esconderia o do trait sem aviso.
 */
trait GuardsRelationManagerAccess
{
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return is_subclass_of($pageClass, Page::class)
            && $pageClass::getResource()::canView($ownerRecord)
            && parent::canViewForRecord($ownerRecord, $pageClass);
    }

    public function mountGuardsRelationManagerAccess(): void
    {
        $this->abortUnlessOwnerRecordIsViewable();
    }

    public function renderingGuardsRelationManagerAccess(): void
    {
        $this->abortUnlessOwnerRecordIsViewable();
    }

    protected function abortUnlessOwnerRecordIsViewable(): void
    {
        abort_unless(
            isset($this->ownerRecord, $this->pageClass)
                && static::canViewForRecord($this->ownerRecord, $this->pageClass),
            403,
        );
    }
}
