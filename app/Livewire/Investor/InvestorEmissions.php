<?php

namespace App\Livewire\Investor;

use App\Models\Emission;
use App\Models\Investor;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('investor.layout')]
#[Title('Minhas Emissoes')]
class InvestorEmissions extends Component
{
    #[Computed]
    public function investor(): Investor
    {
        $investor = auth('investor')->user();

        abort_unless($investor instanceof Investor, 403);

        return $investor;
    }

    /**
     * @return Collection<int, Emission>
     */
    #[Computed]
    public function emissions(): Collection
    {
        return $this->investor
            ->emissions()
            ->orderBy('name')
            ->get();
    }

    public function render(): View
    {
        return view('livewire.investor.investor-emissions', [
            'emissions' => $this->emissions,
        ]);
    }
}
