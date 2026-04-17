<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Actions\Expenses\BuildExpenseCalendar;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Models\Emission;
use App\Models\Expense;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;

class ExpenseCalendar extends Page
{
    protected static string $resource = ExpenseResource::class;

    protected static ?string $title = 'Calendário de pagamentos';

    protected static ?string $breadcrumb = 'Calendário';

    protected string $view = 'filament.resources.expenses.pages.expense-calendar';

    public string $visibleMonth = '';

    public ?string $selectedEmissionId = null;

    public ?string $selectedCategory = null;

    public function mount(): void
    {
        $this->visibleMonth = now()->format('Y-m');
    }

    /**
     * @return array{
     *     month_label: string,
     *     visible_month: string,
     *     summary: array{event_count: int, total_amount: string, operation_count: int},
     *     weeks: array<int, array<int, array{
     *         date: string,
     *         day_number: string,
     *         is_current_month: bool,
     *         is_today: bool,
     *         events: array<int, array{
     *             id: string,
     *             date: string,
     *             amount: float,
     *             operation: string,
     *             category: string,
     *             service_provider: string,
     *             amount_label: string,
     *             period_label: string
     *         }>
     *     }>>
     * }
     */
    public function getCalendarData(): array
    {
        return app(BuildExpenseCalendar::class)->handle($this->visibleMonth, [
            'emission_id' => $this->selectedEmissionId,
            'category' => $this->selectedCategory,
        ]);
    }

    /**
     * @return array<int|string, string>
     */
    public function getEmissionOptions(): array
    {
        return Emission::query()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function getCategoryOptions(): array
    {
        return Expense::CATEGORY_OPTIONS;
    }

    public function previousMonth(): void
    {
        $this->visibleMonth = $this->resolveVisibleMonth()
            ->subMonthNoOverflow()
            ->format('Y-m');
    }

    public function nextMonth(): void
    {
        $this->visibleMonth = $this->resolveVisibleMonth()
            ->addMonthNoOverflow()
            ->format('Y-m');
    }

    public function currentMonth(): void
    {
        $this->visibleMonth = now()->format('Y-m');
    }

    public function clearFilters(): void
    {
        $this->selectedEmissionId = null;
        $this->selectedCategory = null;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('list')
                ->label('Listagem')
                ->icon(Heroicon::OutlinedListBullet)
                ->color('gray')
                ->url(ListExpenses::getUrl()),
            Action::make('create')
                ->label('Criar despesa')
                ->icon(Heroicon::OutlinedPlus)
                ->url(CreateExpense::getUrl()),
        ];
    }

    protected function resolveVisibleMonth(): CarbonImmutable
    {
        $visibleMonth = trim($this->visibleMonth);

        if (preg_match('/^\d{4}-\d{2}$/', $visibleMonth) === 1) {
            return CarbonImmutable::createFromFormat('Y-m', $visibleMonth)->startOfMonth();
        }

        return now()->toImmutable()->startOfMonth();
    }
}
