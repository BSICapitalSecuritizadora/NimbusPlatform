@php
    use Illuminate\Support\Number;

    /** @var \App\Models\MeasurementPlanSet $planSet */
    $planSet = $getRecord();
    $locale = config('app.locale');

    $fund = $planSet->construction_fund_amount;
    $incurred = (float) $planSet->incurred_amount;
    $available = (float) $planSet->available_balance;
@endphp

<dl class="bsi-plan-financials">
    <div class="bsi-plan-financials-row">
        <dt class="bsi-plan-financials-label">Fundo de Obra</dt>
        <dd class="bsi-plan-financials-value">{{ blank($fund) ? '—' : Number::currency($fund, 'BRL', $locale) }}</dd>
    </div>
    <div class="bsi-plan-financials-row">
        <dt class="bsi-plan-financials-label">Custo Incorrido</dt>
        <dd class="bsi-plan-financials-value">{{ Number::currency($incurred, 'BRL', $locale) }}</dd>
    </div>
    <div class="bsi-plan-financials-row bsi-plan-financials-row--balance">
        <dt class="bsi-plan-financials-label">Saldo Disponível</dt>
        <dd class="bsi-plan-financials-value bsi-plan-financials-balance {{ $available < 0 ? 'bsi-plan-financials-balance--negative' : 'bsi-plan-financials-balance--positive' }}">{{ Number::currency($available, 'BRL', $locale) }}</dd>
    </div>
</dl>
