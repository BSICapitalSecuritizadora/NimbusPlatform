<?php

namespace App\Livewire\Investor;

use App\Models\Document;
use App\Models\Emission;
use App\Models\Investor;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('investor.layout')]
#[Title('Meus Documentos')]
class DocumentList extends Component
{
    use WithPagination;

    public string $search = '';

    public string $category = '';

    public string $emissionId = '';

    public string $dateFrom = '';

    public string $dateTo = '';

    public bool $onlyNew = false;

    public ?string $previousPortalSeenAt = null;

    public function mount(): void
    {
        $investor = $this->resolveInvestor();

        $this->previousPortalSeenAt = $investor->last_portal_seen_at?->toDateTimeString();

        $investor->forceFill([
            'last_portal_seen_at' => now(),
        ])->save();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'category', 'emissionId', 'dateFrom', 'dateTo', 'onlyNew'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function investor(): Investor
    {
        return $this->resolveInvestor();
    }

    /**
     * @return LengthAwarePaginator<int, Document>
     */
    #[Computed]
    public function documents(): LengthAwarePaginator
    {
        return Document::query()
            ->with('emissions')
            ->visibleToInvestor($this->investor->id)
            ->when($this->search !== '', function (Builder $query): void {
                $query->where('title', 'like', "%{$this->search}%");
            })
            ->when($this->category !== '', function (Builder $query): void {
                $query->where('category', $this->category);
            })
            ->when($this->emissionId !== '', function (Builder $query): void {
                $query->whereHas('emissions', function (Builder $emissionQuery): void {
                    $emissionQuery->whereKey($this->emissionId);
                });
            })
            ->when($this->dateFrom !== '', function (Builder $query): void {
                $query->where(function (Builder $dateQuery): void {
                    $dateQuery->whereDate('published_at', '>=', $this->dateFrom)
                        ->orWhere(function (Builder $nullQuery): void {
                            $nullQuery->whereNull('published_at')
                                ->whereDate('created_at', '>=', $this->dateFrom);
                        });
                });
            })
            ->when($this->dateTo !== '', function (Builder $query): void {
                $query->where(function (Builder $dateQuery): void {
                    $dateQuery->whereDate('published_at', '<=', $this->dateTo)
                        ->orWhere(function (Builder $nullQuery): void {
                            $nullQuery->whereNull('published_at')
                                ->whereDate('created_at', '<=', $this->dateTo);
                        });
                });
            })
            ->when($this->onlyNew, function (Builder $query): void {
                $query->where(function (Builder $newQuery): void {
                    $newQuery->where('published_at', '>', $this->portalSeenReference())
                        ->orWhere(function (Builder $nullQuery): void {
                            $nullQuery->whereNull('published_at')
                                ->where('created_at', '>', $this->portalSeenReference());
                        });
                });
            })
            ->orderByDesc('published_at')
            ->orderByDesc('created_at')
            ->paginate(20);
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function categoryOptions(): array
    {
        return Document::query()
            ->visibleToInvestor($this->investor->id)
            ->distinct()
            ->pluck('category')
            ->filter()
            ->sort()
            ->values()
            ->mapWithKeys(fn (string $category): array => [
                $category => Document::CATEGORY_OPTIONS[$category] ?? $category,
            ])
            ->all();
    }

    /**
     * @return Collection<int, Emission>
     */
    #[Computed]
    public function emissions(): Collection
    {
        return Emission::query()
            ->whereHas('investors', function (Builder $query): void {
                $query->whereKey($this->investor->id);
            })
            ->orderBy('name')
            ->get();
    }

    public function render(): View
    {
        return view('livewire.investor.document-list', [
            'documents' => $this->documents,
            'categoryOptions' => $this->categoryOptions,
            'emissions' => $this->emissions,
            'investor' => $this->investor,
            'previousPortalSeenAt' => $this->previousPortalSeenAt,
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
