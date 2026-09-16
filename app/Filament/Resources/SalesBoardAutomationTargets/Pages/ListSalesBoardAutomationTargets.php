<?php

namespace App\Filament\Resources\SalesBoardAutomationTargets\Pages;

use App\Enums\SalesBoardAutomationTargetStatus;
use App\Filament\Resources\SalesBoardAutomationTargets\SalesBoardAutomationTargetResource;
use App\Models\SalesBoardAutomationRun;
use App\Support\SalesBoards\SalesBoardAutomationConfig;
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

        if (! SalesBoardAutomationConfig::enabled()) {
            return 'Automação desligada no interruptor global: nenhum processamento mensal é executado nem registrado enquanto ela estiver assim. '.$lastRun;
        }

        return $run === null
            ? 'Automação global ligada, mas ainda sem execução: nenhuma execução registrada até agora.'
            : 'Automação global ligada. '.$lastRun;
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
     */
    public function getTabs(): array
    {
        return [
            'pendentes' => Tab::make('Pendentes de ação')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', [
                    SalesBoardAutomationTargetStatus::Blocked,
                    SalesBoardAutomationTargetStatus::Failed,
                    SalesBoardAutomationTargetStatus::Pending,
                ]))
                ->badge(fn (): int => static::getResource()::getEloquentQuery()
                    ->whereNot('status', SalesBoardAutomationTargetStatus::Satisfied)
                    ->count()),

            'bloqueados' => Tab::make('Bloqueados pela fonte')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('status', SalesBoardAutomationTargetStatus::Blocked)),

            'falhas' => Tab::make('Falhas técnicas')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('status', SalesBoardAutomationTargetStatus::Failed)),

            'satisfeitos' => Tab::make('Satisfeitos')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('status', SalesBoardAutomationTargetStatus::Satisfied)),

            'todos' => Tab::make('Todos'),
        ];
    }
}
