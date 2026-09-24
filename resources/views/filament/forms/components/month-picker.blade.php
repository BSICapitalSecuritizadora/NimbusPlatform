@php
    $statePath = $getStatePath();
    $id = $getId();
    $isDisabled = $isDisabled();
    $panelId = $id . '-panel';
    $errorId = $id . '-range-error';
@endphp

<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    <div
        x-data="bsiMonthPicker({
            state: $wire.{{ $applyStateBindingModifiers("\$entangle('{$statePath}')") }},
            notBeforeStatePath: @js($getNotBeforeStatePath()),
            notAfterStatePath: @js($getNotAfterStatePath()),
            rangeMessage: @js($getRangeMessage()),
        })"
        wire:ignore
        wire:key="{{ $getLivewireKey() }}.month-picker"
        x-on:click.outside="close(false)"
        x-on:focusout="onFocusOut($event)"
        {{ $getExtraAttributeBag()->class(['bsi-month-picker']) }}
    >
        <div
            x-ref="control"
            class="fi-input-wrp bsi-month-picker-control"
            x-bind:class="{ 'bsi-month-picker-control-invalid': hasRangeError || formatError }"
        >
            <input
                x-ref="input"
                id="{{ $id }}"
                type="text"
                inputmode="numeric"
                autocomplete="off"
                maxlength="7"
                placeholder="{{ $getPlaceholder() }}"
                aria-haspopup="dialog"
                aria-controls="{{ $panelId }}"
                x-bind:aria-expanded="isOpen ? 'true' : 'false'"
                x-bind:aria-invalid="hasRangeError || formatError ? 'true' : 'false'"
                x-bind:aria-describedby="hasRangeError || formatError ? @js($errorId) : null"
                x-model="typedText"
                x-on:input="formatTyped()"
                x-on:change="commitTyped()"
                x-on:keydown.enter.prevent="commitTyped()"
                x-on:keydown.down.prevent="open()"
                @disabled($isDisabled)
                class="bsi-month-picker-input"
            />

            <button
                x-ref="toggle"
                type="button"
                x-on:click="toggle()"
                aria-haspopup="dialog"
                aria-controls="{{ $panelId }}"
                x-bind:aria-expanded="isOpen ? 'true' : 'false'"
                aria-label="Abrir seletor de competência"
                title="Selecionar competência"
                @disabled($isDisabled)
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
        >
            <div class="bsi-month-picker-header">
                <button
                    type="button"
                    x-on:click="changeYear(-1)"
                    aria-label="Ano anterior"
                    title="Ano anterior"
                    class="fi-fo-date-time-picker-nav-btn"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M11.78 5.22a.75.75 0 0 1 0 1.06L8.06 10l3.72 3.72a.75.75 0 1 1-1.06 1.06l-4.25-4.25a.75.75 0 0 1 0-1.06l4.25-4.25a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd" /></svg>
                </button>

                <span class="bsi-month-picker-year" aria-live="polite" x-text="focusedYear"></span>

                <button
                    type="button"
                    x-on:click="changeYear(1)"
                    aria-label="Próximo ano"
                    title="Próximo ano"
                    class="fi-fo-date-time-picker-nav-btn"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M8.22 5.22a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06-1.06L11.94 10 8.22 6.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" /></svg>
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

            <div class="bsi-month-picker-footer fi-fo-date-time-picker-panel-footer">
                <button
                    type="button"
                    x-on:click="clear()"
                    class="bsi-month-picker-footer-btn bsi-month-picker-footer-btn-clear fi-fo-date-time-picker-footer-btn fi-fo-date-time-picker-footer-btn-clear"
                >
                    Limpar
                </button>

                <button
                    type="button"
                    x-on:click="selectCurrentMonth()"
                    x-bind:disabled="isCurrentMonthDisabled()"
                    class="bsi-month-picker-footer-btn bsi-month-picker-footer-btn-today fi-fo-date-time-picker-footer-btn fi-fo-date-time-picker-footer-btn-today"
                >
                    Este mês
                </button>
            </div>
        </div>

        <p
            x-cloak
            x-show="hasRangeError || formatError"
            x-text="formatError || rangeMessage"
            id="{{ $errorId }}"
            role="alert"
            class="bsi-month-picker-error"
        ></p>
    </div>
</x-dynamic-component>
