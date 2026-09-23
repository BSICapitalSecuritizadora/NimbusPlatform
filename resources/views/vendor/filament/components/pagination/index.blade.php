@props([
    'currentPageOptionProperty' => 'tableRecordsPerPage',
    'extremeLinks' => false,
    'paginator',
    'pageOptions' => [],
])

@php
    use Illuminate\Contracts\Pagination\CursorPaginator;

    $isRtl = __('filament-panels::layout.direction') === 'rtl';
    $isSimple = ! $paginator instanceof \Illuminate\Pagination\LengthAwarePaginator;
@endphp

<nav
    aria-label="{{ __('filament::components/pagination.label') }}"
    {{
        $attributes->class([
            'fi-pagination',
            'fi-simple' => $isSimple,
        ])
    }}
>
    @if (! $paginator->onFirstPage())
        @php
            if ($paginator instanceof CursorPaginator) {
                $wireClickAction = "setPage('{$paginator->previousCursor()->encode()}', '{$paginator->getCursorName()}')";
            } else {
                $wireClickAction = "previousPage('{$paginator->getPageName()}')";
            }
        @endphp

        <x-filament::button
            color="gray"
            rel="prev"
            :wire:click="$wireClickAction"
            :wire:key="$this->getId() . '.pagination.previous'"
            class="fi-pagination-previous-btn"
        >
            {{ __('filament::components/pagination.actions.previous.label') }}
        </x-filament::button>
    @endif

    @if (! $isSimple)
        <span class="fi-pagination-overview">
            {{
                trans_choice(
                    'filament::components/pagination.overview',
                    $paginator->total(),
                    [
                        'first' => \Illuminate\Support\Number::format($paginator->firstItem() ?? 0),
                        'last' => \Illuminate\Support\Number::format($paginator->lastItem() ?? 0),
                        'total' => \Illuminate\Support\Number::format($paginator->total()),
                    ],
                )
            }}
        </span>
    @endif

    @if (count($pageOptions) > 1)
        @php
            $currentOption = (string) (
                ($this->{$currentPageOptionProperty} ?? null)
                ?: ($paginator instanceof \Illuminate\Contracts\Pagination\Paginator ? $paginator->perPage() : null)
                ?: ($pageOptions[0] ?? 25)
            );
        @endphp

        <div class="fi-pagination-records-per-page-select-ctn">
            {{-- Hidden native select to preserve automated test compatibility and wire:model bindings --}}
            <select
                wire:model.live="{{ $currentPageOptionProperty }}"
                class="sr-only"
                tabindex="-1"
                aria-hidden="true"
            >
                @foreach ($pageOptions as $option)
                    <option value="{{ $option }}">{{ $option }}</option>
                @endforeach
            </select>

            <x-filament::dropdown
                placement="bottom-start"
                shift
                flip
                teleport
                width="auto"
                :wire:key="$this->getId() . '.pagination.records-per-page'"
                class="fi-pagination-records-per-page-dropdown"
            >
                <x-slot name="trigger">
                    <button
                        type="button"
                        class="fi-pagination-records-per-page-btn"
                        aria-label="{{ __('filament::components/pagination.fields.records_per_page.label') }}"
                        title="{{ __('filament::components/pagination.fields.records_per_page.label') }}"
                    >
                        <span class="fi-pagination-records-per-page-prefix">
                            {{ __('filament::components/pagination.fields.records_per_page.label') }}
                        </span>
                        <span class="fi-pagination-records-per-page-value">
                            {{ $currentOption === 'all' ? __('filament::components/pagination.fields.records_per_page.options.all') : $currentOption }}
                        </span>
                        <svg
                            class="fi-pagination-records-per-page-chevron"
                            xmlns="http://www.w3.org/2000/svg"
                            viewBox="0 0 20 20"
                            fill="currentColor"
                            aria-hidden="true"
                        >
                            <path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
                        </svg>
                    </button>
                </x-slot>

                <div
                    class="fi-pagination-records-per-page-menu"
                    role="listbox"
                    aria-label="{{ __('filament::components/pagination.fields.records_per_page.label') }}"
                    x-on:keydown.down.prevent.stop="$focus.next()"
                    x-on:keydown.up.prevent.stop="$focus.previous()"
                    x-on:keydown.home.prevent.stop="$focus.first()"
                    x-on:keydown.end.prevent.stop="$focus.last()"
                    x-on:keydown.escape.stop="close()"
                >
                    @foreach ($pageOptions as $option)
                        @php
                            $isSelected = (string) $currentOption === (string) $option;
                            $optionLabel = $option === 'all' ? __('filament::components/pagination.fields.records_per_page.options.all') : $option;
                        @endphp
                        <button
                            type="button"
                            x-on:click="$wire.set(@js($currentPageOptionProperty), @js($option)); (typeof close === 'function' && close())"
                            @class([
                                'fi-pagination-records-per-page-item',
                                'fi-selected' => $isSelected,
                            ])
                            role="option"
                            aria-selected="{{ $isSelected ? 'true' : 'false' }}"
                            tabindex="0"
                        >
                            <span class="fi-pagination-records-per-page-item-label">{{ $optionLabel }}</span>
                            @if ($isSelected)
                                <svg
                                    class="fi-pagination-records-per-page-item-check"
                                    xmlns="http://www.w3.org/2000/svg"
                                    viewBox="0 0 20 20"
                                    fill="currentColor"
                                    aria-hidden="true"
                                >
                                    <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd" />
                                </svg>
                            @endif
                        </button>
                    @endforeach
                </div>
            </x-filament::dropdown>
        </div>
    @endif

    @if ($paginator->hasMorePages())
        @php
            if ($paginator instanceof CursorPaginator) {
                $wireClickAction = "setPage('{$paginator->nextCursor()->encode()}', '{$paginator->getCursorName()}')";
            } else {
                $wireClickAction = "nextPage('{$paginator->getPageName()}')";
            }
        @endphp

        <x-filament::button
            color="gray"
            rel="next"
            :wire:click="$wireClickAction"
            :wire:key="$this->getId() . '.pagination.next'"
            class="fi-pagination-next-btn"
        >
            {{ __('filament::components/pagination.actions.next.label') }}
        </x-filament::button>
    @endif

    @if ((! $isSimple) && $paginator->hasPages())
        <ol class="fi-pagination-items">
            @if (! $paginator->onFirstPage())
                @if ($extremeLinks)
                    <x-filament::pagination.item
                        :aria-label="__('filament::components/pagination.actions.first.label')"
                        :icon="$isRtl ? \Filament\Support\Icons\Heroicon::ChevronDoubleRight : \Filament\Support\Icons\Heroicon::ChevronDoubleLeft"
                        :icon-alias="
                            $isRtl
                            ? \Filament\Support\View\SupportIconAlias::PAGINATION_FIRST_BUTTON_RTL
                            : \Filament\Support\View\SupportIconAlias::PAGINATION_FIRST_BUTTON
                        "
                        rel="first"
                        :wire:click="'gotoPage(1, \'' . $paginator->getPageName() . '\')'"
                        :wire:key="$this->getId() . '.pagination.first'"
                    />
                @endif

                <x-filament::pagination.item
                    :aria-label="__('filament::components/pagination.actions.previous.label')"
                    :icon="$isRtl ? \Filament\Support\Icons\Heroicon::ChevronRight : \Filament\Support\Icons\Heroicon::ChevronLeft"
                    :icon-alias="
                        $isRtl
                        ? [
                            \Filament\Support\View\SupportIconAlias::PAGINATION_PREVIOUS_BUTTON_RTL,
                            \Filament\Support\View\SupportIconAlias::PAGINATION_PREVIOUS_BUTTON,
                        ]
                        : \Filament\Support\View\SupportIconAlias::PAGINATION_PREVIOUS_BUTTON
                    "
                    rel="prev"
                    :wire:click="'previousPage(\'' . $paginator->getPageName() . '\')'"
                    :wire:key="$this->getId() . '.pagination.previous'"
                />
            @endif

            @foreach ($paginator->render()->offsetGet('elements') as $element)
                @if (is_string($element))
                    <x-filament::pagination.item disabled :label="$element" />
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        <x-filament::pagination.item
                            :active="$page === $paginator->currentPage()"
                            :aria-label="trans_choice('filament::components/pagination.actions.go_to_page.label', $page, ['page' => \Illuminate\Support\Number::format($page)])"
                            :label="\Illuminate\Support\Number::format($page)"
                            :wire:click="'gotoPage(' . $page . ', \'' . $paginator->getPageName() . '\')'"
                            :wire:key="$this->getId() . '.pagination.' . $paginator->getPageName() . '.' . $page"
                        />
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <x-filament::pagination.item
                    :aria-label="__('filament::components/pagination.actions.next.label')"
                    :icon="$isRtl ? \Filament\Support\Icons\Heroicon::ChevronLeft : \Filament\Support\Icons\Heroicon::ChevronRight"
                    :icon-alias="
                        $isRtl
                        ? [
                            \Filament\Support\View\SupportIconAlias::PAGINATION_NEXT_BUTTON_RTL,
                            \Filament\Support\View\SupportIconAlias::PAGINATION_NEXT_BUTTON,
                        ]
                        : \Filament\Support\View\SupportIconAlias::PAGINATION_NEXT_BUTTON
                    "
                    rel="next"
                    :wire:click="'nextPage(\'' . $paginator->getPageName() . '\')'"
                    :wire:key="$this->getId() . '.pagination.next'"
                />

                @if ($extremeLinks)
                    <x-filament::pagination.item
                        :aria-label="__('filament::components/pagination.actions.last.label')"
                        :icon="$isRtl ? \Filament\Support\Icons\Heroicon::ChevronDoubleLeft : \Filament\Support\Icons\Heroicon::ChevronDoubleRight"
                        :icon-alias="
                            $isRtl
                            ? \Filament\Support\View\SupportIconAlias::PAGINATION_LAST_BUTTON_RTL
                            : \Filament\Support\View\SupportIconAlias::PAGINATION_LAST_BUTTON
                        "
                        rel="last"
                        :wire:click="'gotoPage(' . $paginator->lastPage() . ', \'' . $paginator->getPageName() . '\')'"
                        :wire:key="$this->getId() . '.pagination.last'"
                    />
                @endif
            @endif
        </ol>
    @endif
</nav>
