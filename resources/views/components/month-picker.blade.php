@props([
    'id' => null,
    'wireModel' => null,
    'placeholder' => 'mm/aaaa',
    'disabled' => false,
    'columns' => 4,
    'displayMode' => 'verbose',
    'clearValue' => '',
    'required' => false,
])

@php
    $id = $id ?? 'month-picker-' . bin2hex(random_bytes(4));
    $panelId = $id . '-panel';
    $wireModelName = $attributes->wire('model')->value() ?: $wireModel;
    $isLive = $attributes->wire('model')->hasModifier('live');
    $entangleExpression = $wireModelName
        ? ($isLive ? "\$wire.entangle('{$wireModelName}').live" : "\$wire.entangle('{$wireModelName}')")
        : 'null';
@endphp

<div
    x-data="bsiMonthPicker({
        id: @js($id),
        state: {{ $entangleExpression }},
        columns: @js($columns),
        displayMode: @js($displayMode),
        clearValue: @js($clearValue),
    })"
    wire:ignore
    wire:key="{{ $id }}-month-picker"
    x-on:click.outside="close(false)"
    x-on:focusout="onFocusOut($event)"
    {{ $attributes->whereDoesntStartWith('wire:')->class(['bsi-month-picker']) }}
>
    <div
        x-ref="control"
        class="fi-input-wrp bsi-month-picker-control"
        x-on:click="toggle()"
    >
        <input
            x-ref="input"
            id="{{ $id }}"
            type="text"
            readonly
            autocomplete="off"
            placeholder="{{ $placeholder }}"
            aria-haspopup="dialog"
            aria-controls="{{ $panelId }}"
            x-bind:aria-expanded="isOpen ? 'true' : 'false'"
            x-bind:value="typedText"
            x-on:keydown.enter.prevent="toggle()"
            x-on:keydown.space.prevent="toggle()"
            x-on:keydown.down.prevent="open()"
            @disabled($disabled)
            @required($required)
            class="bsi-month-picker-input cursor-pointer"
        />

        <button
            x-ref="toggle"
            type="button"
            x-on:click.stop="toggle()"
            tabindex="-1"
            aria-haspopup="dialog"
            aria-controls="{{ $panelId }}"
            x-bind:aria-expanded="isOpen ? 'true' : 'false'"
            aria-label="Abrir seletor de competência"
            title="Selecionar competência"
            @disabled($disabled)
            class="bsi-month-picker-toggle"
        >
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" />
            </svg>
        </button>
    </div>

    <div
        x-ref="panel"
        x-show="isOpen"
        x-cloak
        id="{{ $panelId }}"
        role="dialog"
        aria-label="Selecionar competência"
        x-on:keydown="onPanelKeydown($event)"
        class="bsi-month-picker-panel"
        data-columns="{{ $columns }}"
    >
        <div class="bsi-month-picker-header">
            <button
                type="button"
                x-on:click="changeYear(-1)"
                aria-label="Ano anterior"
                title="Ano anterior"
                class="bsi-month-picker-nav-btn"
            >
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4" aria-hidden="true"><path fill-rule="evenodd" d="M11.78 5.22a.75.75 0 0 1 0 1.06L8.06 10l3.72 3.72a.75.75 0 1 1-1.06 1.06l-4.25-4.25a.75.75 0 0 1 0-1.06l4.25-4.25a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd" /></svg>
            </button>

            <span class="bsi-month-picker-year" aria-live="polite" x-text="focusedYear"></span>

            <button
                type="button"
                x-on:click="changeYear(1)"
                aria-label="Próximo ano"
                title="Próximo ano"
                class="bsi-month-picker-nav-btn"
            >
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4" aria-hidden="true"><path fill-rule="evenodd" d="M8.22 5.22a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06-1.06L11.94 10 8.22 6.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" /></svg>
            </button>
        </div>

        <div class="bsi-month-picker-grid">
            <template x-for="month in 12" x-bind:key="month">
                <button
                    type="button"
                    x-on:click="select(focusedYear, month)"
                    x-bind:aria-disabled="isMonthDisabled(focusedYear, month) ? 'true' : 'false'"
                    x-bind:tabindex="month === focusedMonth ? 0 : -1"
                    x-bind:data-month="month"
                    x-bind:aria-label="monthLongLabel(month) + ' de ' + focusedYear"
                    x-bind:aria-pressed="isMonthSelected(focusedYear, month) ? 'true' : 'false'"
                    x-bind:aria-current="isCurrentMonth(focusedYear, month) ? 'date' : null"
                    x-bind:class="{
                        'bsi-month-picker-month-selected': isMonthSelected(focusedYear, month),
                        'bsi-month-picker-month-current': isCurrentMonth(focusedYear, month),
                    }"
                    x-text="monthShortLabel(month)"
                    class="bsi-month-picker-month"
                ></button>
            </template>
        </div>

        <div class="bsi-month-picker-footer">
            <button
                type="button"
                x-on:click="clear()"
                class="bsi-month-picker-footer-btn bsi-month-picker-footer-btn-clear"
            >
                Limpar
            </button>

            <button
                type="button"
                x-on:click="selectCurrentMonth()"
                x-bind:disabled="isCurrentMonthDisabled()"
                class="bsi-month-picker-footer-btn bsi-month-picker-footer-btn-today"
            >
                Este mês
            </button>
        </div>
    </div>
</div>
