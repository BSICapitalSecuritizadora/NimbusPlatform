@php
    $accountUser = auth()->user();
    $accountTheme = $accountUser instanceof \App\Models\User
        ? $accountUser->preferences?->theme?->value
        : null;
    $accountDensity = $accountUser instanceof \App\Models\User
        ? $accountUser->preferences?->table_density?->value
        : null;
@endphp
@if ($accountTheme || $accountDensity === 'compact')
<script>
    try {
        @if ($accountTheme)
        window.localStorage.setItem('theme', @js($accountTheme));
        @endif
        @if ($accountDensity === 'compact')
        document.documentElement.dataset.tableDensity = 'compact';
        @endif
    } catch (error) {}
</script>
@endif
