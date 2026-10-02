<?php

namespace App\Filament\Support;

use Filament\Forms\Components\TextInput;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filtro "Importação nº" das tabelas cujos registros uma importação cria em
 * lote -- contratos, parcelas e unidades.
 *
 * Lê `import_run_id`, carimbado só no insert da importação: mostra o que aquela
 * execução criou, nunca o que ela alterou (isso está na lista de alterações da
 * própria importação). Registros criados à mão, ou antes da coluna existir, não
 * têm importação e nunca aparecem aqui.
 */
final class ImportRunFilter
{
    public const NAME = 'import_run';

    public static function make(): Filter
    {
        return Filter::make(self::NAME)
            ->label('Importação nº')
            ->schema([
                TextInput::make('import_run_id')
                    ->label('Importação nº')
                    ->prefix('#')
                    ->numeric()
                    ->integer()
                    ->minValue(1),
            ])
            ->query(fn (Builder $query, array $data): Builder => $query->when(
                self::runId($data),
                fn (Builder $query, int $runId): Builder => $query->where($query->getModel()->qualifyColumn('import_run_id'), $runId),
            ))
            ->indicateUsing(fn (array $data): ?string => self::runId($data) === null
                ? null
                : 'Importação nº '.self::runId($data));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function runId(array $data): ?int
    {
        $value = $data['import_run_id'] ?? null;

        if (! is_numeric($value) || ((int) $value < 1)) {
            return null;
        }

        return (int) $value;
    }
}
