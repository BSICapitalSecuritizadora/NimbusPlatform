<?php

namespace App\Filament\Widgets\Dashboard;

use App\Models\Obligation;
use Carbon\Carbon;
use Filament\Widgets\Widget;

class DeadlinesWidget extends Widget
{
    protected string $view = 'filament.widgets.dashboard.deadlines-widget';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 5;

    protected function getViewData(): array
    {
        if (! auth()->user()->can('obligations.view')) {
            return ['groups' => []];
        }

        $now = Carbon::now();
        $todayEnd = $now->copy()->endOfDay();
        $in3Days = $now->copy()->addDays(3)->endOfDay();
        $in7Days = $now->copy()->addDays(7)->endOfDay();

        $query = Obligation::query()->whereNotIn('status', ['concluida'])->with('emission');
        $allPending = $query->get();

        $vencidos = $allPending->filter(fn ($o) => $o->status === 'vencida' || ($o->due_date && $o->due_date->isBefore($now->startOfDay())));
        $hoje = $allPending->filter(fn ($o) => $o->due_date && $o->due_date->isSameDay($now));
        $proximos3 = $allPending->filter(fn ($o) => $o->due_date && $o->due_date->isAfter($todayEnd) && $o->due_date->isBefore($in3Days));
        $proximos7 = $allPending->filter(fn ($o) => $o->due_date && $o->due_date->isAfter($in3Days) && $o->due_date->isBefore($in7Days));
        $semPrazo = $allPending->filter(fn ($o) => ! $o->due_date);

        return [
            'groups' => [
                'Vencidos' => ['items' => $vencidos->take(5), 'color' => 'danger', 'count' => $vencidos->count()],
                'Vencem Hoje' => ['items' => $hoje->take(5), 'color' => 'danger', 'count' => $hoje->count()],
                'Próx. 3 Dias' => ['items' => $proximos3->take(5), 'color' => 'warning', 'count' => $proximos3->count()],
                'Próx. 7 Dias' => ['items' => $proximos7->take(5), 'color' => 'warning', 'count' => $proximos7->count()],
                'Sem Prazo' => ['items' => $semPrazo->take(5), 'color' => 'gray', 'count' => $semPrazo->count()],
            ],
        ];
    }
}
