<?php

namespace App\Filament\Resources\SalesBoardAutomationTargets\Pages;

use App\Enums\SalesBoardAutomationTargetStatus;
use App\Filament\Resources\SalesBoardAutomationTargets\SalesBoardAutomationTargetResource;
use App\Models\SalesBoardAutomationRun;
use App\Support\SalesBoards\SalesBoardAutomationConfig;
use App\Support\SalesBoards\SalesBoardAutomationNotices;
use App\Support\SalesBoards\SalesBoardAutomationPerimeter;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListSalesBoardAutomationTargets extends ListRecords
{
    protected static string $resource = SalesBoardAutomationTargetResource::class;

    protected static ?string $title = 'Automação do Quadro de Vendas';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-sales-board-automation-page',
    ];

    /**
     * O perímetro lido uma vez por renderização: a aba e o badge perguntam a
     * mesma coisa, e o provider custa uma consulta por Emissão automatizada.
     */
    private ?SalesBoardAutomationPerimeter $perimeter = null;

    /**
     * O cabeçalho responde a pergunta que antecede todas as outras: o scheduler
     * rodou? Sem ela, uma lista vazia é indistinguível de uma automação morta.
     *
     * Desligada, a automação não registra execução nenhuma -- nem a própria
     * passagem --, e o cabeçalho diz isso antes de tudo: "desligada" e "ligada,
     * mas sem execução" pedem ações diferentes de quem está olhando.
     */
    public function getSubheading(): ?string
    {
        $run = SalesBoardAutomationRun::query()->latest('started_at')->first();
        $lastRun = $this->lastRunSummary($run);

        $notices = implode(' ', SalesBoardAutomationNotices::forAutomationScreen());
        $notices = $notices === '' ? '' : ' '.$notices;

        if (! SalesBoardAutomationConfig::enabled()) {
            return 'Automação desligada no interruptor global: nenhum processamento mensal é executado nem registrado enquanto ela estiver assim. '.$lastRun.$notices;
        }

        return ($run === null
            ? 'Automação global ligada, mas ainda sem execução: nenhuma execução registrada até agora.'
            : 'Automação global ligada. '.$lastRun).$notices;
    }

    private function lastRunSummary(?SalesBoardAutomationRun $run): string
    {
        if ($run === null) {
            return 'Nenhuma execução registrada até agora.';
        }

        return sprintf(
            'Última execução em %s · %s · competência limite %s · %d gerado(s), %d existente(s), %d bloqueado(s), %d falha(s).',
            $run->started_at->format('d/m/Y H:i'),
            $run->status->label(),
            $run->latestDueMonthLabel(),
            $run->generated_count,
            $run->existing_count,
            $run->blocked_count,
            $run->failed_count,
        );
    }

    /**
     * As abas são o recorte operacional: o que exige ação primeiro.
     *
     * "Pendentes de ação" é o que a automação ainda atende: alvo aberto de
     * empreendimento que está no perímetro hoje, a partir da competência em que
     * ele entrou. Um alvo de Emissão devolvida ao legado não pede ação de
     * ninguém -- e contá-lo deixava o badge sem nunca zerar.
     */
    public function getTabs(): array
    {
        return [
            'pendentes' => Tab::make('Pendentes de ação')
                ->modifyQueryUsing(fn (Builder $query): Builder => $this->pendingActionQuery($query))
                ->badge(fn (): int => $this->pendingActionQuery(static::getResource()::getEloquentQuery())->count()),

            'bloqueados' => Tab::make('Bloqueados pela fonte')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('status', SalesBoardAutomationTargetStatus::Blocked)),

            'falhas' => Tab::make('Falhas técnicas')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('status', SalesBoardAutomationTargetStatus::Failed)),

            'satisfeitos' => Tab::make('Satisfeitos')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('status', SalesBoardAutomationTargetStatus::Satisfied)),

            'encerrados' => Tab::make('Encerrados')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('status', SalesBoardAutomationTargetStatus::Closed)),

            'todos' => Tab::make('Todos'),
        ];
    }

    private function pendingActionQuery(Builder $query): Builder
    {
        $this->perimeter ??= SalesBoardAutomationPerimeter::current();

        return $this->perimeter->constrain(
            $query->whereIn('status', SalesBoardAutomationTargetStatus::openCases())
        );
    }
}
