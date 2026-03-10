<?php

namespace App\Livewire\Investor;

use App\Models\Document;
use App\Models\Emission;
use Livewire\Component;
use Livewire\WithPagination;

class DocumentList extends Component
{
    use WithPagination;

    public $search = '';
    public $category = '';
    public $emissionId = '';
    public $dateFrom = '';
    public $dateTo = '';
    public $onlyNew = false;

    public function updated($property)
    {
        if (in_array($property, ['search', 'category', 'emissionId', 'dateFrom', 'dateTo', 'onlyNew'])) {
            $this->resetPage();
        }
    }

    public function render()
    {
        $investor = auth('investor')->user();

        $query = Document::query()
            ->visibleToInvestor($investor->id)
            ->when($this->search, fn ($q) =>
                $q->where('title', 'like', "%{$this->search}%")
            )
            ->when($this->category, fn ($q) =>
                $q->where('category', $this->category)
            )
            ->when($this->emissionId, fn ($q) =>
                $q->whereHas('emissions', fn ($qq) => $qq->whereKey($this->emissionId))
            )
            ->when($this->dateFrom, fn ($q) =>
                $q->where(function ($qDate) {
                    $qDate->whereDate('published_at', '>=', $this->dateFrom)
                          ->orWhere(function ($qNull) {
                              $qNull->whereNull('published_at')->whereDate('created_at', '>=', $this->dateFrom);
                          });
                })
            )
            ->when($this->dateTo, fn ($q) =>
                $q->where(function ($qDate) {
                    $qDate->whereDate('published_at', '<=', $this->dateTo)
                          ->orWhere(function ($qNull) {
                              $qNull->whereNull('published_at')->whereDate('created_at', '<=', $this->dateTo);
                          });
                })
            )
            ->when($this->onlyNew, fn ($q) =>
                $q->where(function($qq) use ($investor) {
                    $qq->where('published_at', '>', $investor->last_portal_seen_at ?? '1970-01-01')
                       ->orWhere(function($qqq) use ($investor) {
                           $qqq->whereNull('published_at')->where('created_at', '>', $investor->last_portal_seen_at ?? '1970-01-01');
                       });
                })
            )
            ->orderByDesc('published_at')
            ->orderByDesc('created_at');

        return view('livewire.investor.document-list', [
            'documents' => $query->paginate(20),
            'categories' => Document::query()->visibleToInvestor($investor->id)->distinct()->pluck('category')->filter()->values(),
            'emissions' => Emission::query()->whereHas('investors', fn($q) => $q->whereKey($investor->id))->get(),
            'investor' => $investor,
        ]);
    }
}
