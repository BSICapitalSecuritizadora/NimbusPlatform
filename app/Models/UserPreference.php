<?php

namespace App\Models;

use App\Enums\SidebarBehavior;
use App\Enums\TableDensity;
use App\Enums\UserInterfaceTheme;
use Database\Factories\UserPreferenceFactory;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Route;

/**
 * Per-user UI preferences for the admin panel.
 *
 * The database is the source of truth; localStorage only mirrors the theme
 * for instant application. Absence of a row means "framework defaults" — no
 * global behavior changes for users who never saved preferences.
 */
class UserPreference extends Model
{
    /** @use HasFactory<UserPreferenceFactory> */
    use HasFactory;

    public const HOME_ADMIN = 'admin';

    public const HOME_DASHBOARD = 'dashboard';

    public const HOME_PROFILE = 'profile';

    public const HOME_SITE = 'site';

    public const PER_PAGE_OPTIONS = [10, 25, 50, 100];

    protected $fillable = [
        'theme',
        'sidebar_behavior',
        'table_density',
        'per_page',
        'home_page',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'theme' => UserInterfaceTheme::class,
            'sidebar_behavior' => SidebarBehavior::class,
            'table_density' => TableDensity::class,
            'per_page' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Resolve the preferences for fill/display purposes without persisting.
     */
    public static function forUser(User $user): self
    {
        return $user->preferences ?? new self([
            'theme' => UserInterfaceTheme::System,
            'sidebar_behavior' => SidebarBehavior::Remember,
            'table_density' => TableDensity::Comfortable,
            'per_page' => 25,
            'home_page' => self::HOME_ADMIN,
        ]);
    }

    /**
     * Home destinations a user may choose, filtered by authorization.
     *
     * @return array<string, string>
     */
    public static function homePageOptions(?User $user): array
    {
        $options = [
            self::HOME_ADMIN => __('Painel administrativo'),
            self::HOME_PROFILE => __('Minha Conta'),
            self::HOME_SITE => __('Site institucional'),
        ];

        if ($user && filled($user->email_verified_at)) {
            $options = array_merge([self::HOME_DASHBOARD => __('Visão geral')], $options);
        }

        return $options;
    }

    /**
     * Resolve a safe home URL for the user, falling back to the panel.
     */
    public static function homeUrlFor(?User $user): string
    {
        $fallback = '/admin';

        if (! $user) {
            return $fallback;
        }

        $homePage = $user->preferences?->home_page ?? self::HOME_ADMIN;

        return match ($homePage) {
            self::HOME_DASHBOARD => filled($user->email_verified_at) && Route::has('dashboard')
                ? route('dashboard')
                : $fallback,
            self::HOME_PROFILE => Route::has('filament.admin.pages.minha-conta')
                ? route('filament.admin.pages.minha-conta')
                : $fallback,
            self::HOME_SITE => Route::has('site.home')
                ? route('site.home')
                : $fallback,
            default => $fallback,
        };
    }

    /**
     * Global table page-size options honoring the current user's preference.
     *
     * Returns null to keep the framework default; only extends the option list
     * when the user explicitly chose 100, which stock tables do not offer.
     *
     * @return array<int>|null
     */
    public static function globalTablePageOptions(): ?array
    {
        if (self::currentPerPage() === 100) {
            return [5, 10, 25, 50, 100];
        }

        return null;
    }

    /**
     * Default page size for a table, or null to keep table/framework behavior.
     *
     * This is a default, not an override: tables with an explicit
     * `defaultPaginationPageOption()` keep it, since per-table configuration
     * runs after the global baseline. Never returns a size outside the
     * table's own options, so tables with a custom option list keep working
     * exactly as before.
     */
    public static function globalDefaultTablePerPage(Table $table): ?int
    {
        $perPage = self::currentPerPage();

        if ($perPage === null) {
            return null;
        }

        return in_array($perPage, $table->getPaginationPageOptions(), true)
            ? $perPage
            : null;
    }

    /**
     * Explicitly saved page size of the current user, if any.
     */
    public static function currentPerPage(): ?int
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return null;
        }

        return $user->preferences?->per_page;
    }
}
