<?php

namespace App\Filament\Support;

use Filament\Actions\Action;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Pergunta à policy uma vez por requisição, por ação e por registro.
 *
 * Numa tabela o Filament consulta a autorização padrão da mesma ação de linha
 * várias vezes: para decidir se ela aparece, se fica desabilitada e -- com
 * `authorizationTooltip()` -- qual motivo mostrar. Quando a policy também
 * consulta o banco, como as guardas de integridade do Quadro de Vendas, cada
 * pergunta vira uma consulta por linha.
 *
 * A resposta fica guardada numa propriedade protegida do componente, que o
 * Livewire não serializa: morre com a requisição. A próxima requisição -- o
 * clique que executa a ação, inclusive -- pergunta de novo e nunca decide com um
 * estado velho. O registro entra na chave com o estado de exclusão lógica, para
 * que restaurar ou excluir na mesma requisição não reaproveite a resposta do
 * estado anterior.
 */
trait MemoizesRecordActionAuthorization
{
    /**
     * @var array<string, Response|null>
     */
    protected array $recordActionAuthorizationResponses = [];

    public function getDefaultActionAuthorizationResponse(Action $action): ?Response
    {
        $record = $action->getRecord();

        if ((! $record instanceof Model) || (! $record->exists)) {
            return parent::getDefaultActionAuthorizationResponse($action);
        }

        $key = implode('|', [
            $action::class,
            (string) $action->getName(),
            $record::class,
            (string) $record->getKey(),
            (method_exists($record, 'trashed') && $record->trashed()) ? 'excluído' : 'ativo',
        ]);

        if (! array_key_exists($key, $this->recordActionAuthorizationResponses)) {
            $this->recordActionAuthorizationResponses[$key] = parent::getDefaultActionAuthorizationResponse($action);
        }

        return $this->recordActionAuthorizationResponses[$key];
    }
}
