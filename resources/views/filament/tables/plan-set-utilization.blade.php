@php
    use Illuminate\Support\Number;

    /** @var \App\Models\MeasurementPlanSet $planSet */
    $planSet = $getRecord();

    $used = (float) $planSet->used_percentage;
    $barWidth = min(100, max(0, $used));
@endphp

<div class="bsi-plan-utilization">
    <span class="bsi-plan-utilization-value">{{ Number::format($used, 2, null, config('app.locale')) }}%</span>
    <div class="bsi-plan-utilization-track" aria-hidden="true">
        <div class="bsi-plan-utilization-fill" style="width: {{ number_format($barWidth, 2, '.', '') }}%"></div>
    </div>
</div>
