<?php

namespace App\Filament\Widgets\Dashboard;

use Filament\Widgets\Widget;

class ShortcutsWidget extends Widget
{
    protected string $view = 'filament.widgets.dashboard.shortcuts-widget';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 1;
}
