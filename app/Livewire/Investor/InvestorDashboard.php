<?php

namespace App\Livewire\Investor;

use App\Models\Document;
use App\Models\Investor;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('investor.layout')]
#[Title('Inicio - Portal do Investidor')]
class InvestorDashboard extends Component
{
    public ?string $previousPortalSeenAt = null;

    public function mount(): void
    {
        $investor = $this->resolveInvestor();

        $this->previousPortalSeenAt = $investor->last_portal_seen_at?->toDateTimeString();

        $investor->forceFill([
            'last_portal_seen_at' => now(),
        ])->save();
    }

    #[Computed]
    public function investor(): Investor
    {
        return $this->resolveInvestor();
    }

    #[Computed]
    public function newDocumentsCount(): int
    {
        return Document::query()
            ->visibleToInvestor($this->investor->id)
            ->where(function (Builder $query): void {
                $query->where('published_at', '>', $this->portalSeenReference())
                    ->orWhere(function (Builder $nullQuery): void {
                        $nullQuery->whereNull('published_at')
                            ->where('created_at', '>', $this->portalSeenReference());
                    });
            })
            ->count();
    }

    public function render(): View
    {
        return view('livewire.investor.investor-dashboard', [
            'investor' => $this->investor,
            'newDocumentsCount' => $this->newDocumentsCount,
        ]);
    }

    protected function resolveInvestor(): Investor
    {
        $investor = auth('investor')->user();

        abort_unless($investor instanceof Investor, 403);

        return $investor;
    }

    protected function portalSeenReference(): string
    {
        return $this->previousPortalSeenAt ?? '1970-01-01 00:00:00';
    }
}
