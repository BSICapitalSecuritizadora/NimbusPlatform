<?php

namespace App\Providers\Filament;

use App\Enums\AccessPermission;
use App\Filament\Pages\Auth\CustomLogin;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Nimbus\NotificationSettings;
use App\Filament\Resources\Activities\ActivityResource;
use App\Filament\Resources\DocumentDownloads\DocumentDownloadResource;
use App\Filament\Resources\Nimbus\AccessTokens\AccessTokenResource;
use App\Filament\Resources\Nimbus\Announcements\AnnouncementResource;
use App\Filament\Resources\Nimbus\DocumentCategories\DocumentCategoryResource;
use App\Filament\Resources\Nimbus\GeneralDocuments\GeneralDocumentResource;
use App\Filament\Resources\Nimbus\NotificationOutboxes\NotificationOutboxResource;
use App\Filament\Resources\Nimbus\PortalDocuments\PortalDocumentResource;
use App\Filament\Resources\Nimbus\PortalUsers\PortalUserResource;
use App\Filament\Resources\ReminderLogs\ReminderLogResource;
use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Http\Middleware\EnsureUserIsApproved;
use App\Http\Middleware\SetSecurityHeaders;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Arr;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(CustomLogin::class)
            ->brandName('BSI Capital')
            ->brandLogo(fn () => view('filament.logo'))
            ->brandLogoHeight('2.5rem')
            ->colors([
                'danger' => Color::Red,
                'gray' => Color::hex('#e6e4e4'),
                'info' => Color::hex('#091b23'),
                'primary' => Color::hex('#b7832f'),
                'warning' => Color::Amber,
            ])
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => '<meta name="robots" content="noindex,nofollow">',
                scopes: CustomLogin::class,
            )
            ->navigationGroups([
                NavigationGroup::make('Comercial'),
                NavigationGroup::make('Operações'),
                NavigationGroup::make('Financeiro'),
                NavigationGroup::make('Governança & Risco'),
                NavigationGroup::make('Gestão Documental Externa'),
                NavigationGroup::make('Dados de Mercado'),
                NavigationGroup::make('Site Institucional'),
                NavigationGroup::make('Administração'),
            ])
            ->navigationItems([
                NavigationItem::make('Gestão Documental')
                    ->group('Gestão Documental Externa')
                    ->icon(Heroicon::OutlinedFolder)
                    ->sort(0)
                    ->visible(fn (): bool => auth()->user()?->canAny([
                        'nimbus.document-categories.view',
                        'nimbus.general-documents.view',
                        'nimbus.portal-documents.view',
                    ]) ?? false)
                    ->url(fn (): string => static::firstAccessibleUrl([
                        'nimbus.document-categories.view' => DocumentCategoryResource::class,
                        'nimbus.general-documents.view' => GeneralDocumentResource::class,
                        'nimbus.portal-documents.view' => PortalDocumentResource::class,
                    ]))
                    ->isActiveWhen(fn (): bool => request()->routeIs(DocumentCategoryResource::getNavigationItemActiveRoutePattern()) || request()->routeIs(GeneralDocumentResource::getNavigationItemActiveRoutePattern()) || request()->routeIs(PortalDocumentResource::getNavigationItemActiveRoutePattern())),
                NavigationItem::make('Comunicação')
                    ->group('Gestão Documental Externa')
                    ->icon(Heroicon::OutlinedMegaphone)
                    ->sort(10)
                    ->visible(fn (): bool => auth()->user()?->canAny([
                        'nimbus.announcements.view',
                        'nimbus.notification-outboxes.view',
                        'nimbus.notification-settings.view',
                    ]) ?? false)
                    ->url(fn (): string => static::firstAccessibleUrl([
                        'nimbus.announcements.view' => AnnouncementResource::class,
                        'nimbus.notification-outboxes.view' => NotificationOutboxResource::class,
                        'nimbus.notification-settings.view' => NotificationSettings::class,
                    ]))
                    ->isActiveWhen(fn (): bool => request()->routeIs(AnnouncementResource::getNavigationItemActiveRoutePattern()) || request()->routeIs(NotificationOutboxResource::getNavigationItemActiveRoutePattern()) || request()->routeIs(NotificationSettings::getNavigationItemActiveRoutePattern())),
                NavigationItem::make('Acessos e Usuários')
                    ->group('Gestão Documental Externa')
                    ->icon(Heroicon::OutlinedUsers)
                    ->sort(20)
                    ->visible(fn (): bool => auth()->user()?->canAny([
                        'nimbus.portal-users.view',
                        'nimbus.access-tokens.view',
                    ]) ?? false)
                    ->url(fn (): string => static::firstAccessibleUrl([
                        'nimbus.portal-users.view' => PortalUserResource::class,
                        'nimbus.access-tokens.view' => AccessTokenResource::class,
                    ]))
                    ->isActiveWhen(fn (): bool => request()->routeIs(PortalUserResource::getNavigationItemActiveRoutePattern()) || request()->routeIs(AccessTokenResource::getNavigationItemActiveRoutePattern())),
                NavigationItem::make('Auditoria')
                    ->group('Administração')
                    ->icon(Heroicon::OutlinedShieldExclamation)
                    ->sort(20)
                    ->visible(fn (): bool => auth()->user()?->canAny([
                        'audit.activities.view',
                        AccessPermission::ReminderLogsView->value,
                        'audit.document-downloads.view',
                    ]) ?? false)
                    ->url(fn (): string => static::firstAccessibleUrl([
                        'audit.activities.view' => ActivityResource::class,
                        AccessPermission::ReminderLogsView->value => ReminderLogResource::class,
                        'audit.document-downloads.view' => DocumentDownloadResource::class,
                    ]))
                    ->isActiveWhen(fn (): bool => request()->routeIs(ActivityResource::getNavigationItemActiveRoutePattern()) || request()->routeIs(ReminderLogResource::getNavigationItemActiveRoutePattern()) || request()->routeIs(DocumentDownloadResource::getNavigationItemActiveRoutePattern())),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
            ->middleware([
                SetSecurityHeaders::class,
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                EnsureUserIsApproved::class,
                EnsureTwoFactorEnabled::class,
            ]);
    }

    /**
     * Resolve the URL of the first destination the current user is allowed to open.
     *
     * Parent navigation items group several resources, so linking them to a fixed
     * destination would send users without that specific permission to a 403 page.
     *
     * @param  array<string, class-string>  $candidates  Map of permission name to resource or page class.
     */
    protected static function firstAccessibleUrl(array $candidates): string
    {
        $user = auth()->user();

        foreach ($candidates as $permission => $class) {
            if ($user?->can($permission)) {
                return $class::getUrl(panel: 'admin');
            }
        }

        return Arr::first($candidates)::getUrl(panel: 'admin');
    }
}
