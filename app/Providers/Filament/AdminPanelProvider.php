<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Nimbus\NimbusDashboard;
use App\Filament\Pages\Nimbus\NotificationSettings;
use App\Filament\Resources\Banks\BankResource;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Expenses\Pages\ExpenseCalendar;
use App\Filament\Resources\FundApplications\FundApplicationResource;
use App\Filament\Resources\FundNames\FundNameResource;
use App\Filament\Resources\Funds\FundResource;
use App\Filament\Resources\FundTypes\FundTypeResource;
use App\Filament\Resources\Nimbus\AccessTokens\AccessTokenResource;
use App\Filament\Resources\Nimbus\Announcements\AnnouncementResource;
use App\Filament\Resources\Nimbus\DocumentCategories\DocumentCategoryResource;
use App\Filament\Resources\Nimbus\GeneralDocuments\GeneralDocumentResource;
use App\Filament\Resources\Nimbus\NotificationOutboxes\NotificationOutboxResource;
use App\Filament\Resources\Nimbus\PortalDocuments\PortalDocumentResource;
use App\Filament\Resources\Nimbus\PortalUsers\PortalUserResource;
use App\Filament\Resources\Nimbus\Submissions\SubmissionResource;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(\App\Filament\Pages\Auth\CustomLogin::class)
            ->brandName('BSI Capital')
            ->brandLogo(fn () => view('filament.logo'))
            ->brandLogoHeight('2.5rem')
            ->colors([
                'gray' => Color::hex('#e6e4e4'),
                'info' => Color::hex('#091b23'),
                'primary' => Color::hex('#a06e28'),
                'warning' => Color::hex('#a06e28'),
            ])
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->navigationGroups([
                NavigationGroup::make('Gestão Documental Externa'),
                NavigationGroup::make('Auditoria'),
                NavigationGroup::make('Comercial'),
                NavigationGroup::make('Cadastro'),
                NavigationGroup::make('Gestão'),
                NavigationGroup::make('Gestão de Acesso'),
                NavigationGroup::make('Recrutamento'),
                NavigationGroup::make('Relatórios'),
                NavigationGroup::make('Configurações'),
            ])
            ->navigationItems([
                NavigationItem::make('Visão Geral')
                    ->group('Gestão Documental Externa')
                    ->icon(Heroicon::OutlinedSquares2x2)
                    ->sort(-20)
                    ->visible(fn (): bool => auth()->user()?->can('nimbus.submissions.view') ?? false)
                    ->url(fn (): string => NimbusDashboard::getUrl(panel: 'admin'))
                    ->isActiveWhen(fn (): bool => request()->routeIs(NimbusDashboard::getNavigationItemActiveRoutePattern()) || request()->routeIs(SubmissionResource::getNavigationItemActiveRoutePattern())),
                NavigationItem::make('Administração')
                    ->group('Gestão Documental Externa')
                    ->icon(Heroicon::OutlinedCog6Tooth)
                    ->sort(-10)
                    ->visible(fn (): bool => auth()->user()?->can('nimbus.portal-users.view') ?? false)
                    ->url(fn (): string => PortalUserResource::getUrl(panel: 'admin'))
                    ->isActiveWhen(fn (): bool => request()->routeIs(PortalUserResource::getNavigationItemActiveRoutePattern()) || request()->routeIs(AccessTokenResource::getNavigationItemActiveRoutePattern())),
                NavigationItem::make('Gestão Documental')
                    ->group('Gestão Documental Externa')
                    ->icon(Heroicon::OutlinedFolder)
                    ->sort(0)
                    ->visible(fn (): bool => auth()->user()?->canAny([
                        'nimbus.document-categories.view',
                        'nimbus.general-documents.view',
                        'nimbus.portal-documents.view',
                    ]) ?? false)
                    ->url(fn (): string => DocumentCategoryResource::getUrl(panel: 'admin'))
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
                    ->url(fn (): string => AnnouncementResource::getUrl(panel: 'admin'))
                    ->isActiveWhen(fn (): bool => request()->routeIs(AnnouncementResource::getNavigationItemActiveRoutePattern()) || request()->routeIs(NotificationOutboxResource::getNavigationItemActiveRoutePattern()) || request()->routeIs(NotificationSettings::getNavigationItemActiveRoutePattern())),
                NavigationItem::make('Fundos')
                    ->group('Cadastro')
                    ->icon(Heroicon::OutlinedRectangleStack)
                    ->sort(20)
                    ->visible(fn (): bool => auth()->user()?->can('funds.view') ?? false)
                    ->url(fn (): string => FundResource::getUrl(panel: 'admin'))
                    ->isActiveWhen(fn (): bool => request()->routeIs(FundResource::getNavigationItemActiveRoutePattern()) || request()->routeIs(FundTypeResource::getNavigationItemActiveRoutePattern()) || request()->routeIs(FundNameResource::getNavigationItemActiveRoutePattern()) || request()->routeIs(FundApplicationResource::getNavigationItemActiveRoutePattern()) || request()->routeIs(BankResource::getNavigationItemActiveRoutePattern())),
                NavigationItem::make('Despesas')
                    ->group('Gestão')
                    ->icon(Heroicon::OutlinedRectangleStack)
                    ->sort(10)
                    ->visible(fn (): bool => auth()->user()?->can('expenses.view') ?? false)
                    ->url(fn (): string => ExpenseResource::getUrl(panel: 'admin'))
                    ->isActiveWhen(fn (): bool => request()->routeIs(ExpenseResource::getNavigationItemActiveRoutePattern()) || request()->routeIs(ExpenseCalendar::getRouteName())),
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
                \App\Http\Middleware\EnsureUserIsApproved::class,
                \App\Http\Middleware\EnsureTwoFactorEnabled::class,
            ]);
    }
}
