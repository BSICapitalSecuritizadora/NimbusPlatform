<div>
    @php
        $navigation = filament()->getNavigation();
        $isRtl = __('filament-panels::layout.direction') === 'rtl';
        $isSidebarCollapsibleOnDesktop = filament()->isSidebarCollapsibleOnDesktop();
        $isSidebarFullyCollapsibleOnDesktop = filament()->isSidebarFullyCollapsibleOnDesktop();
        $hasNavigation = filament()->hasNavigation();
        $hasTopbar = filament()->hasTopbar();
    @endphp

    {{-- format-ignore-start --}}
    <div
        x-data="{}"
        @if ($isSidebarCollapsibleOnDesktop || $isSidebarFullyCollapsibleOnDesktop)
            x-cloak
        @else
            x-cloak="-lg"
        @endif
        x-bind:class="{ 'fi-sidebar-open': $store.sidebar.isOpen }"
        id="fi-main-sidebar"
        class="fi-sidebar fi-main-sidebar"
    >
        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::SIDEBAR_START) }}

        <div class="fi-sidebar-header-ctn">
            <header class="fi-sidebar-header">
                {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::SIDEBAR_LOGO_BEFORE) }}

                <!-- Expanded Logo -->
                <div
                    x-show="$store.sidebar.isOpen"
                    class="fi-sidebar-header-logo-ctn"
                >
                    @if ($homeUrl = filament()->getHomeUrl())
                        <a {{ \Filament\Support\generate_href_html($homeUrl) }} class="fi-sidebar-logo-link">
                            <x-filament-panels::logo />
                        </a>
                    @else
                        <div class="fi-sidebar-logo-link">
                            <x-filament-panels::logo />
                        </div>
                    @endif
                </div>

                <!-- Collapsed Compact Monogram -->
                <div
                    x-show="! $store.sidebar.isOpen"
                    x-cloak
                    class="fi-sidebar-header-compact-logo-ctn"
                >
                    @if ($homeUrl = filament()->getHomeUrl())
                        <a
                            {{ \Filament\Support\generate_href_html($homeUrl) }}
                            class="fi-sidebar-compact-logo-link"
                            x-data="{ tooltip: { content: 'BSI Capital', placement: 'right' } }"
                            x-tooltip.html="tooltip"
                            aria-label="BSI Capital"
                        >
                            <img
                                src="{{ asset('images/logo_sidebar.png') }}"
                                alt="BSI Capital"
                                class="fi-sidebar-compact-logo-img"
                            />
                        </a>
                    @else
                        <div
                            class="fi-sidebar-compact-logo-link"
                            x-data="{ tooltip: { content: 'BSI Capital', placement: 'right' } }"
                            x-tooltip.html="tooltip"
                            aria-label="BSI Capital"
                        >
                            <img
                                src="{{ asset('images/logo_sidebar.png') }}"
                                alt="BSI Capital"
                                class="fi-sidebar-compact-logo-img"
                            />
                        </div>
                    @endif
                </div>

                {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::SIDEBAR_LOGO_AFTER) }}

                <!-- Toggle Buttons Container in Sidebar Header -->
                @if ($isSidebarCollapsibleOnDesktop || $isSidebarFullyCollapsibleOnDesktop)
                    <div class="fi-sidebar-header-toggle-ctn">
                        <!-- Collapse button (visible when expanded) -->
                        <button
                            type="button"
                            aria-label="{{ __('Recolher barra lateral') }}"
                            x-cloak
                            x-data="{ tooltip: { content: 'Recolher barra lateral', placement: 'bottom' } }"
                            x-tooltip.html="tooltip"
                            aria-controls="fi-main-sidebar"
                            x-bind:aria-expanded="$store.sidebar.isOpen"
                            x-on:click="$store.sidebar.close()"
                            x-show="$store.sidebar.isOpen"
                            class="fi-sidebar-collapse-btn fi-sidebar-close-collapse-sidebar-btn"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="fi-sidebar-toggle-svg">
                                <rect width="18" height="18" x="3" y="3" rx="2"/>
                                <path d="M9 3v18"/>
                            </svg>
                        </button>

                        <!-- Expand button (visible when collapsed) -->
                        <button
                            type="button"
                            aria-label="{{ __('Abrir barra lateral') }}"
                            x-cloak
                            x-data="{ tooltip: { content: 'Abrir barra lateral', placement: 'right' } }"
                            x-tooltip.html="tooltip"
                            aria-controls="fi-main-sidebar"
                            x-bind:aria-expanded="$store.sidebar.isOpen"
                            x-on:click="$store.sidebar.open()"
                            x-show="! $store.sidebar.isOpen"
                            class="fi-sidebar-collapse-btn fi-sidebar-open-collapse-sidebar-btn"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="fi-sidebar-toggle-svg">
                                <rect width="18" height="18" x="3" y="3" rx="2"/>
                                <path d="M9 3v18"/>
                            </svg>
                        </button>
                    </div>
                @endif
            </header>
        </div>

        @if (filament()->hasTenancy() && filament()->hasTenantMenu())
            <x-filament-panels::tenant-menu />
        @endif

        @if (filament()->isGlobalSearchEnabled() && filament()->getGlobalSearchPosition() === \Filament\Enums\GlobalSearchPosition::Sidebar)
            <div
                x-show="$store.sidebar.isOpen"
                class="fi-sidebar-search-ctn"
            >
                @livewire(Filament\Livewire\GlobalSearch::class)
            </div>
        @endif

        <nav
            aria-label="{{ __('filament-panels::layout.navigation.label') }}"
            class="fi-sidebar-nav"
        >
            {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::SIDEBAR_NAV_START) }}

            <ul class="fi-sidebar-nav-groups">
                @foreach ($navigation as $group)
                    @php
                        $isGroupActive = $group->isActive();
                        $isGroupCollapsible = $group->isCollapsible();
                        $groupIcon = $group->getIcon();
                        $groupItems = $group->getItems();
                        $groupLabel = $group->getLabel();
                        $groupExtraSidebarAttributeBag = $group->getExtraSidebarAttributeBag();
                    @endphp

                    <x-filament-panels::sidebar.group
                        :active="$isGroupActive"
                        :collapsible="$isGroupCollapsible"
                        :icon="$groupIcon"
                        :items="$groupItems"
                        :label="$groupLabel"
                        :attributes="\Filament\Support\prepare_inherited_attributes($groupExtraSidebarAttributeBag)"
                    />
                @endforeach
            </ul>

            <script>
                var collapsedGroups = JSON.parse(
                    localStorage.getItem('collapsedGroups'),
                )

                if (collapsedGroups === null || collapsedGroups === 'null') {
                    localStorage.setItem(
                        'collapsedGroups',
                        JSON.stringify(@js(
                        collect($navigation)
                            ->filter(fn (\Filament\Navigation\NavigationGroup $group): bool => $group->isCollapsed() && ! $group->isActive())
                            ->map(fn (\Filament\Navigation\NavigationGroup $group): string => $group->getLabel())
                            ->values()
                            ->all()
                    )),
                    )
                }

                collapsedGroups = JSON.parse(
                    localStorage.getItem('collapsedGroups'),
                ) || []

                document
                    .querySelectorAll('.fi-sidebar-group')
                    .forEach((group) => {
                        if (group.classList.contains('fi-active')) {
                            // Active group must remain open
                            return
                        }

                        if (!collapsedGroups.includes(group.dataset.groupLabel)) {
                            return
                        }

                        // Alpine.js loads too slow, so attempt to hide a
                        // collapsed sidebar group earlier.
                        var itemsEl = group.querySelector('.fi-sidebar-group-items')
                        if (itemsEl) {
                            itemsEl.style.display = 'none'
                        }
                        group.classList.add('fi-collapsed')
                    })
            </script>

            {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::SIDEBAR_NAV_END) }}
        </nav>

        @php
            $isAuthenticated = filament()->auth()->check();
            $hasDatabaseNotificationsInSidebar = filament()->hasDatabaseNotifications() && filament()->getDatabaseNotificationsPosition() === \Filament\Enums\DatabaseNotificationsPosition::Sidebar;
            $hasUserMenuInSidebar = filament()->hasUserMenu() && filament()->getUserMenuPosition() === \Filament\Enums\UserMenuPosition::Sidebar;
            $shouldRenderFooter = $isAuthenticated && ($hasDatabaseNotificationsInSidebar || $hasUserMenuInSidebar);
        @endphp

        @if ($shouldRenderFooter)
            <div class="fi-sidebar-footer">
                @if ($hasDatabaseNotificationsInSidebar)
                    @livewire(filament()->getDatabaseNotificationsLivewireComponent(), [
                        'lazy' => filament()->hasLazyLoadedDatabaseNotifications(),
                    ])
                @endif

                @if ($hasUserMenuInSidebar)
                    <x-filament-panels::user-menu />
                @endif
            </div>
        @endif

        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::SIDEBAR_FOOTER) }}
    </div>
    {{-- format-ignore-end --}}

    <x-filament-actions::modals />
</div>
