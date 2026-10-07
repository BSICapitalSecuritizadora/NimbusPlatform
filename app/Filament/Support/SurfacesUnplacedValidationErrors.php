<?php

namespace App\Filament\Support;

use Filament\Forms\Components\Contracts\HasNestedRecursiveValidationRules;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Hidden;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Leva cada erro de validação do domínio para onde a pessoa consegue lê-lo.
 *
 * O Filament só desenha um erro no campo cujo statePath bate exatamente com a
 * chave -- ou, nos campos que validam itens aninhados (upload, seleção
 * múltipla...), com o prefixo `campo.` -- e não tem área genérica de erros. O
 * Livewire guarda o resto no error bag sem mostrar nada. Uma recusa do domínio
 * com chave sem campo no formulário (a cobertura de arquivos da Engenharia,
 * `payments` na etapa Pagamento, o antivírus no envio, o comprovante que sumiu
 * do armazenamento) virava um clique que não fazia nada: sem mensagem, sem log.
 *
 * Aqui a chave relativa ganha o statePath do schema. O erro que um campo
 * visível desenha fica nele; o resto vira uma única notificação persistente,
 * com as mensagens sem repetição, no formato das demais recusas do painel.
 * Campo oculto (`Hidden`) não desenha erro, então não conta como lugar.
 */
trait SurfacesUnplacedValidationErrors
{
    /**
     * Separa os erros entre os campos visíveis de `$schema` e uma notificação.
     *
     * Devolve a exceção para relançar só com os erros que têm campo -- a
     * própria `$exception`, intacta, quando todos já estavam no lugar, como os
     * da validação do próprio formulário --, ou `null` quando nenhum tem: aí a
     * notificação é tudo o que a pessoa vê, e quem chama decide como parar.
     * Sem schema não há campo, e tudo vai para a notificação.
     */
    protected function placeValidationErrors(ValidationException $exception, ?Schema $schema, string $title): ?ValidationException
    {
        $statePath = (string) $schema?->getStatePath();
        $fields = collect($schema?->getFlatFields() ?? [])
            ->reject(fn (Field $field): bool => $field instanceof Hidden);
        $placed = [];
        $unplaced = [];
        $wasRelocated = false;

        foreach ($exception->errors() as $key => $messages) {
            $path = (($statePath === '') || str_starts_with((string) $key, "{$statePath}.")) ? (string) $key : "{$statePath}.{$key}";
            $wasRelocated = $wasRelocated || ($path !== (string) $key);

            if ($this->isDrawnByAField($path, $fields)) {
                $placed[$path] = $messages;

                continue;
            }

            $unplaced[] = $messages;
        }

        if ($unplaced !== []) {
            Notification::make()
                ->danger()
                ->title($title)
                ->body(collect($unplaced)->flatten()->unique()->implode(' '))
                ->persistent()
                ->send();
        }

        if ($placed === []) {
            return null;
        }

        if (($unplaced === []) && (! $wasRelocated)) {
            return $exception;
        }

        return ValidationException::withMessages($placed);
    }

    /**
     * A mesma regra do `Field::wrapEmbeddedHtml()` do Filament para decidir se
     * um campo mostra o erro.
     *
     * @param  Collection<array-key, Field>  $fields
     */
    private function isDrawnByAField(string $path, Collection $fields): bool
    {
        return $fields->contains(function (Field $field) use ($path): bool {
            $fieldPath = (string) $field->getStatePath();

            if ($fieldPath === '') {
                return false;
            }

            return ($path === $fieldPath)
                || (($field instanceof HasNestedRecursiveValidationRules) && str_starts_with($path, "{$fieldPath}."));
        });
    }
}
