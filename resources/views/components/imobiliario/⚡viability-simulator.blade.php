<?php

use Livewire\Component;

new class extends Component
{
    public string $vgv = '';

    public float $potential = 0;

    public string $term = '60';

    public string $indexer = 'CDI';

    public string $rate = '4.5';

    /** @var array<int, string> */
    public array $indexerOptions = ['CDI', 'IPCA'];

    public function updatedVgv(): void
    {
        $this->recalculatePotential();
    }

    public function updatedTerm(): void
    {
        $this->term = (string) $this->normalizeTerm($this->term);

        $this->recalculatePotential();
    }

    public function updatedIndexer(string $value): void
    {
        if (! in_array($value, $this->indexerOptions, true)) {
            $this->indexer = 'CDI';
        }

        $this->recalculatePotential();
    }

    public function updatedRate(): void
    {
        $this->rate = $this->normalizeRate($this->rate);

        $this->recalculatePotential();
    }

    public function getRemunerationLabelProperty(): string
    {
        return $this->indexer.' + '.number_format((float) $this->rate, 2, ',', '.').'%';
    }

    protected function normalizeMoney(string $value): float
    {
        return (float) str_replace(['.', ','], ['', '.'], $value);
    }

    protected function normalizeTerm(mixed $value): int
    {
        return max(1, min(120, (int) $value));
    }

    protected function normalizeRate(mixed $value): string
    {
        $normalized = max(0, min(100, (float) str_replace(',', '.', (string) $value)));

        return rtrim(rtrim(number_format($normalized, 2, '.', ''), '0'), '.');
    }

    protected function recalculatePotential(): void
    {
        if (blank($this->vgv)) {
            $this->potential = 0;

            return;
        }

        $normalizedVgv = $this->normalizeMoney($this->vgv);
        $term = $this->normalizeTerm($this->term);
        $rate = (float) $this->normalizeRate($this->rate);

        /**
         * Illustrative capture ratio:
         * - 65% base at 60 months and CDI + 4.5%
         * - longer terms support slightly more leverage
         * - higher target spread reduces estimated leverage
         * - IPCA is treated more conservatively than CDI for this heuristic
         */
        $captureRatio = 0.65;
        $captureRatio += (($term - 60) / 60) * 0.04;
        $captureRatio += $this->indexer === 'IPCA' ? -0.01 : 0;
        $captureRatio += (4.5 - $rate) * 0.004;
        $captureRatio = max(0.35, min(0.80, $captureRatio));

        $this->potential = round($normalizedVgv * $captureRatio, 2);
    }
};
?>

<div class="p-6 p-lg-8 rounded-4 shadow-xl border border-light" style="background-color: #f8f9fa;">
    <div class="row g-5 align-items-center">
        <div class="col-lg-7">
            <div class="mb-4">
                <h3 class="h4 fw-bold text-dark mb-3">Simulador de Viabilidade CRI</h3>
                <p class="text-muted">Informe o VGV (Valor Global de Venda) do seu projeto para uma estimativa inicial de captação via securitização.</p>
            </div>
            
            <div class="p-4 rounded-4 border mb-4" style="background-color: #f8f9fa;">
                <div class="d-flex align-items-center mb-3">
                    <div class="bg-white shadow-sm p-2 rounded-3 me-3 text-brand" style="border: 1px solid rgba(0,0,0,0.05);">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="6" width="20" height="12" rx="2"></rect><path d="M12 12h.01"></path><path d="M17 12h.01"></path><path d="M7 12h.01"></path></svg>
                    </div>
                    <label class="form-label fw-bold text-uppercase text-muted mb-0" style="font-size: 0.75rem; letter-spacing: 0.5px;">VGV do Projeto (R$)</label>
                </div>
                <div class="mt-1">
                    <input 
                        type="text"
                        wire:model.live.debounce.500ms="vgv" 
                        x-mask:dynamic="$money($input, ',', '.', 2)" 
                        inputmode="decimal"
                        placeholder="Ex: 50.000.000,00"
                        class="form-control form-control-lg shadow-sm border-0"
                        style="background-color: #fff; font-size: 1.15rem; font-weight: 600; color: var(--brand);"
                    />
                </div>
            </div>
            
            <div class="row g-4">
                <div class="col-sm-6">
                    <div class="p-4 rounded-4 border h-100 d-flex flex-column position-relative" style="background-color: #f8f9fa; transition: all 0.3s ease;">
                        <div class="d-flex align-items-center mb-3">
                            <div class="bg-white shadow-sm p-2 rounded-3 me-3 text-brand" style="border: 1px solid rgba(0,0,0,0.05);">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                            </div>
                            <div class="text-xs text-muted text-uppercase fw-bold" style="font-size: 0.75rem; letter-spacing: 0.5px;">Prazo Médio Estimado</div>
                        </div>
                        
                        <div class="display-6 fw-bolder mb-4 text-dark" style="letter-spacing: -0.5px;">{{ $term }} <span class="h5 text-muted fw-normal">meses</span></div>
                        
                        <div class="mt-auto pt-2 border-top">
                            <div class="pt-3">
                                <div class="d-flex justify-content-between text-muted mb-2 px-1" style="font-size: 0.75rem; font-weight: 600;">
                                    <span>1 mês</span>
                                    <span>120 meses</span>
                                </div>
                                <input type="range" class="form-range" min="1" max="120" step="1" wire:model.live.debounce.100ms="term">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6">
                    <div class="p-4 rounded-4 border h-100 d-flex flex-column position-relative" style="background-color: #f8f9fa; transition: all 0.3s ease;">
                        <div class="d-flex align-items-center mb-3">
                            <div class="bg-white shadow-sm p-2 rounded-3 me-3 text-brand" style="border: 1px solid rgba(0,0,0,0.05);">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="2" x2="12" y2="22"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                            </div>
                            <div class="text-xs text-muted text-uppercase fw-bold" style="font-size: 0.75rem; letter-spacing: 0.5px;">Taxa Alvo de Mercado</div>
                        </div>
                        
                        <div class="display-6 fw-bolder mb-4 text-dark" style="letter-spacing: -0.5px;">{{ $this->remunerationLabel }}</div>
                        
                        <div class="mt-auto pt-2 border-top">
                            <div class="row g-3 pt-3">
                                <div class="col-5">
                                    <label class="form-label text-muted mb-2 text-uppercase" style="font-size: 0.7rem; font-weight: 700; letter-spacing: 0.5px;">Indexador</label>
                                    <select wire:model.live="indexer" class="form-select form-select-lg shadow-sm border-0" style="background-color: #fff; font-size: 0.95rem; font-weight: 600; color: var(--text);">
                                        @foreach ($indexerOptions as $option)
                                            <option wire:key="indexer-{{ $option }}" value="{{ $option }}">{{ $option }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-7">
                                    <label class="form-label text-muted mb-2 text-uppercase" style="font-size: 0.7rem; font-weight: 700; letter-spacing: 0.5px;">Taxa (%)</label>
                                    <div class="input-group input-group-lg shadow-sm">
                                        <input
                                            type="number"
                                            min="0"
                                            max="100"
                                            step="0.01"
                                            wire:model.live.debounce.300ms="rate"
                                            inputmode="decimal"
                                            class="form-control border-0"
                                            style="background-color: #fff; font-size: 0.95rem; font-weight: 600; color: var(--text);"
                                        />
                                        <span class="input-group-text border-0" style="background-color: #fff; color: var(--muted); font-weight: 600;">%</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <p class="small text-muted mt-4 mb-0">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="me-1"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                Valores meramente ilustrativos baseados em VGV, prazo e remuneração alvo de mercado.
            </p>
        </div>

        <div class="col-lg-5">
            <div class="p-4 p-lg-5 rounded-4 text-white position-relative overflow-hidden" style="background: var(--brand-strong); box-shadow: 0 20px 40px rgba(0,32,91,0.2);">
                <!-- Decorative element -->
                <div class="position-absolute top-0 end-0 bg-white rounded-circle" style="width: 200px; height: 200px; margin-top: -100px; margin-right: -100px; opacity: 0.1;"></div>
                
                <div class="position-relative z-1">
                    <div class="text-uppercase fw-bold mb-2" style="font-size: 0.75rem; letter-spacing: 0.1em; color: rgba(255,255,255,0.7);">Potencial de Captação</div>
                    <div class="display-6 fw-bold mb-4" style="color: var(--gold);">
                        R$ {{ number_format($potential, 2, ',', '.') }}
                    </div>
                    
                    <hr class="my-4" style="border-color: rgba(255,255,255,0.1);">
                    
                    <p class="small mb-5" style="color: rgba(255,255,255,0.6); line-height: 1.6;">
                        A estrutura final depende de rating do emissor, qualidade das garantias e fluxo de recebíveis auditado.
                    </p>
                    
                    <a href="{{ route('site.contact') }}" class="btn btn-brand w-100 py-3 fw-bold" style="background: var(--gold); border: none; color: var(--brand-strong);">
                        Analisar meu Projeto
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
