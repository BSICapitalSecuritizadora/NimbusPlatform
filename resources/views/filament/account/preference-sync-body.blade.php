@php
    $accountUser = auth()->user();
    $accountPreferences = $accountUser instanceof \App\Models\User
        ? \App\Models\UserPreference::forUser($accountUser)
        : null;
@endphp
@if ($accountPreferences)
<script>
    (function () {
        var allowedThemes = ['light', 'dark', 'system'];
        var themeSyncUrl = @js(route('account.preferences.theme'));
        var csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        var lastSentTheme = null;

        function resolveTheme(value) {
            if (value === 'system') {
                return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            }

            return value;
        }

        function applyTheme(value) {
            if (allowedThemes.indexOf(value) === -1) {
                return;
            }

            try {
                window.localStorage.setItem('theme', value);
            } catch (error) {
                return;
            }

            document.documentElement.classList.toggle('dark', resolveTheme(value) === 'dark');

            try {
                if (window.Alpine && window.Alpine.store('theme') !== undefined) {
                    window.Alpine.store('theme', resolveTheme(value));
                }
            } catch (error) {}

            lastSentTheme = value;
        }

        function applyTableDensity(value) {
            if (value === 'compact') {
                document.documentElement.dataset.tableDensity = 'compact';
            } else {
                delete document.documentElement.dataset.tableDensity;
            }
        }

        function applySidebarBehavior(value) {
            if (value !== 'expanded' && value !== 'collapsed') {
                return;
            }

            if (! window.matchMedia('(min-width: 1024px)').matches) {
                return;
            }

            try {
                var sidebar = window.Alpine && window.Alpine.store('sidebar');

                if (! sidebar) {
                    return;
                }

                if (value === 'expanded') {
                    sidebar.open();
                } else {
                    sidebar.close();
                }
            } catch (error) {}
        }

        window.BsiAccountPrefs = {
            applyTheme: applyTheme,
            applyTableDensity: applyTableDensity,
            applySidebarBehavior: applySidebarBehavior,
        };

        function currentStoredTheme() {
            try {
                return window.localStorage.getItem('theme');
            } catch (error) {
                return null;
            }
        }

        function syncThemeToServer() {
            var theme = currentStoredTheme();

            if (allowedThemes.indexOf(theme) === -1 || theme === lastSentTheme || ! csrfToken) {
                return;
            }

            lastSentTheme = theme;

            fetch(themeSyncUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify({ theme: theme }),
                keepalive: true,
            }).catch(function () {
                lastSentTheme = null;
            });
        }

        var observer = new MutationObserver(function () {
            syncThemeToServer();
        });
        observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
        window.setInterval(syncThemeToServer, 5000);
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'hidden') {
                syncThemeToServer();
            }
        });

        var sidebarBehavior = @js($accountPreferences->sidebar_behavior->value);

        document.addEventListener('alpine:initialized', function () {
            applySidebarBehavior(sidebarBehavior);
        });
        window.setTimeout(function () {
            applySidebarBehavior(sidebarBehavior);
        }, 500);
    })();
</script>
@endif
