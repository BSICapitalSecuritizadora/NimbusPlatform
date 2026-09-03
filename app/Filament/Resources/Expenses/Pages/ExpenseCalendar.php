<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Actions\Expenses\BuildExpenseCalendar;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Models\Emission;
use App\Models\Expense;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

class ExpenseCalendar extends Page
{
    protected static string $resource = ExpenseResource::class;

    protected static ?string $title = 'Calendário de pagamentos';

    protected static ?string $breadcrumb = 'Calendário';

    protected string $view = 'filament.resources.expenses.pages.expense-calendar';

    protected Width|string|null $maxContentWidth = Width::Full;

    protected array $extraBodyAttributes = [
        'class' => 'bsi-expense-calendar-page',
    ];

    public string $visibleMonth = '';

    public ?string $selectedEmissionId = null;

    public ?string $selectedCategory = null;

    public ?string $selectedDate = null;

    public ?string $selectedEventId = null;

    public function mount(): void
    {
        $this->visibleMonth = now()->format('Y-m');

        $category = request()->query('category');

        if (is_string($category) && array_key_exists($category, Expense::CATEGORY_OPTIONS)) {
            $this->selectedCategory = $category;
        }

        $emissionId = request()->query('emission_id');

        if (is_string($emissionId) && Emission::query()->whereKey($emissionId)->exists()) {
            $this->selectedEmissionId = $emissionId;
        }
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
     *             period_label: string,
     *             url: ?string,
     *             is_overdue: bool,
     *             is_due_soon: bool
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

    public function openDay(string $date): void
    {
        $this->selectedDate = $date;
        $this->selectedEventId = null;
    }

    public function closeDay(): void
    {
        $this->selectedDate = null;
    }

    public function openEvent(string $eventId): void
    {
        $this->selectedEventId = $eventId;
        $this->selectedDate = null;
    }

    public function closeEvent(): void
    {
        $this->selectedEventId = null;
    }

    public function closeModals(): void
    {
        $this->selectedDate = null;
        $this->selectedEventId = null;
    }

    public function hasActiveFilters(): bool
    {
        return filled($this->selectedEmissionId) || filled($this->selectedCategory);
    }

    public function getSubheading(): ?string
    {
        return 'Visualize vencimentos e pagamentos previstos por competência.';
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
                ->label('Cadastrar Despesa')
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
