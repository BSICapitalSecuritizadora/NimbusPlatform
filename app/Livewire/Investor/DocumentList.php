<?php

namespace App\Livewire\Investor;

use App\Concerns\HasInvestorSession;
use App\Models\Document;
use App\Models\Emission;
use App\Models\Investor;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
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
    use HasInvestorSession, WithPagination;

    public string $search = '';

    public string $category = '';

    public string $emissionId = '';

    public string $dateFrom = '';

    public string $dateTo = '';

    public bool $onlyNew = false;

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'category', 'emissionId', 'dateFrom', 'dateTo', 'onlyNew'], true)) {
            $this->resetPage();
        }
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->category = '';
        $this->emissionId = '';
        $this->dateFrom = '';
        $this->dateTo = '';
        $this->onlyNew = false;

        $this->resetPage();
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

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function activeFilters(): array
    {
        $selectedEmission = $this->emissionId !== ''
            ? $this->emissions->firstWhere('id', (int) $this->emissionId)
            : null;

        return collect([
            'Busca' => $this->search !== '' ? '"'.$this->search.'"' : null,
            'Categoria' => $this->category !== '' ? ($this->categoryOptions[$this->category] ?? $this->category) : null,
            'Emissão' => $selectedEmission?->name,
            'De' => $this->dateFrom !== '' ? Carbon::parse($this->dateFrom)->format('d/m/Y') : null,
            'Até' => $this->dateTo !== '' ? Carbon::parse($this->dateTo)->format('d/m/Y') : null,
            'Status' => $this->onlyNew ? 'Somente novos desde o último acesso' : null,
        ])
            ->filter(fn (?string $value): bool => filled($value))
            ->all();
    }

    #[Computed]
    public function hasActiveFilters(): bool
    {
        return $this->activeFilters !== [];
    }

    public function render(): View
    {
        return view('livewire.investor.document-list', [
            'documents' => $this->documents,
            'categoryOptions' => $this->categoryOptions,
            'emissions' => $this->emissions,
            'activeFilters' => $this->activeFilters,
            'hasActiveFilters' => $this->hasActiveFilters,
            'investor' => $this->investor,
            'previousPortalSeenAt' => $this->previousPortalSeenAt,
        ]);
    }
}
