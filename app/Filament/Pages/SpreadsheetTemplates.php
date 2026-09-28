<?php

namespace App\Filament\Pages;

use App\Support\SpreadsheetTemplates\SpreadsheetTemplateDefinition;
use App\Support\SpreadsheetTemplates\SpreadsheetTemplateManager;
use App\Support\SpreadsheetTemplates\SpreadsheetTemplateRegistry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\WithFileUploads;
use UnitEnum;

class SpreadsheetTemplates extends Page
{
    use WithFileUploads;

    public const CATEGORY_ALL = 'all';

    /**
     * Pending replacement uploads, keyed by template key.
     *
     * @var array<string, mixed>
     */
    public array $templateFiles = [];

    public string $search = '';

    public string $categoryFilter = self::CATEGORY_ALL;

    protected string $view = 'filament.pages.spreadsheet-templates';

    protected static string|UnitEnum|null $navigationGroup = 'Administração';

    protected static ?string $navigationParentItem = 'Configurações';

    protected static ?int $navigationSort = 93;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentDuplicate;

    protected static ?string $navigationLabel = 'Templates de Planilhas';

    protected static ?string $title = 'Templates de Planilhas';

    protected static ?string $slug = 'settings/templates';

    protected ?string $subheading = 'Gerencie os modelos utilizados nas rotinas de processamento e importação.';

    protected Width|string|null $maxContentWidth = Width::Full;

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-settings-page bsi-spreadsheet-templates-page',
    ];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('settings.view') ?? false;
    }

    /**
     * @return array<string|int, string>
     */
    public function getBreadcrumbs(): array
    {
        return [
            Settings::getUrl(panel: 'admin') => 'Configurações',
            'Templates de Planilhas',
        ];
    }

    /**
     * @return array{groups: array<int, array{category: string, cards: array<int, array<string, mixed>>}>, stats: array{total: int, customized: int}, categoryOptions: array<string, string>}
     */
    protected function getViewData(): array
    {
        $manager = app(SpreadsheetTemplateManager::class);
        $registry = app(SpreadsheetTemplateRegistry::class);

        return [
            'groups' => $this->templateGroups($manager, $registry),
            'stats' => $this->templateStats($manager, $registry),
            'categoryOptions' => $this->categoryOptions($registry),
        ];
    }

    public function saveTemplate(string $key, SpreadsheetTemplateManager $manager, SpreadsheetTemplateRegistry $registry): void
    {
        abort_unless(static::canAccess(), 403);

        $definition = $registry->find($key);

        abort_if($definition === null, 404);
        abort_unless($definition->canReplace(), 403);

        $field = "templateFiles.{$key}";

        $validated = $this->validate([
            $field => [
                'required',
                'file',
                'mimes:xlsx',
                'max:10240',
            ],
        ], [
            "{$field}.required" => 'Selecione uma planilha para atualizar o template.',
            "{$field}.file" => 'Envie um arquivo válido.',
            "{$field}.mimes" => 'Envie uma planilha Excel válida no formato .xlsx.',
            "{$field}.max" => 'A planilha não pode ser maior que 10 MB.',
        ]);

        $manager->store($definition, $validated['templateFiles'][$key]);

        $this->templateFiles[$key] = null;

        Notification::make()
            ->title('Template atualizado com sucesso.')
            ->body($definition->customizedNotificationBody)
            ->success()
            ->send();
    }

    public function restoreDefaultTemplate(string $key, SpreadsheetTemplateManager $manager, SpreadsheetTemplateRegistry $registry): void
    {
        abort_unless(static::canAccess(), 403);

        $definition = $registry->find($key);

        abort_if($definition === null, 404);
        abort_unless($definition->canRestoreDefault(), 403);

        $manager->restoreDefault($definition);

        $this->templateFiles[$key] = null;

        Notification::make()
            ->title('Template padrão restaurado.')
            ->body($definition->restoredNotificationBody)
            ->success()
            ->send();
    }

    /**
     * Category filter options for the search bar.
     *
     * @return array<string, string>
     */
    public function categoryOptions(SpreadsheetTemplateRegistry $registry): array
    {
        $options = [self::CATEGORY_ALL => 'Todas as categorias'];

        foreach ($registry->categories() as $category) {
            $options[$category] = $category;
        }

        return $options;
    }

    /**
     * @return array{total: int, customized: int}
     */
    public function templateStats(SpreadsheetTemplateManager $manager, SpreadsheetTemplateRegistry $registry): array
    {
        $definitions = $registry->all();

        return [
            'total' => $definitions->count(),
            'customized' => $definitions->filter(fn (SpreadsheetTemplateDefinition $definition): bool => $manager->isCustomized($definition))->count(),
        ];
    }

    /**
     * Definitions grouped by category, honoring search and category filters.
     * Each card carries only presentation data; capabilities come from the
     * definition so the Blade never branches on template keys.
     *
     * @return array<int, array{category: string, cards: array<int, array<string, mixed>>}>
     */
    public function templateGroups(SpreadsheetTemplateManager $manager, SpreadsheetTemplateRegistry $registry): array
    {
        $groups = [];

        foreach ($registry->categories() as $category) {
            if ($this->categoryFilter !== self::CATEGORY_ALL && $this->categoryFilter !== $category) {
                continue;
            }

            $cards = $registry->all()
                ->filter(fn (SpreadsheetTemplateDefinition $definition): bool => $definition->category === $category)
                ->filter(fn (SpreadsheetTemplateDefinition $definition): bool => $this->matchesSearch($definition, $manager))
                ->map(fn (SpreadsheetTemplateDefinition $definition): array => $this->card($definition, $manager))
                ->values()
                ->all();

            if ($cards !== []) {
                $groups[] = ['category' => $category, 'cards' => $cards];
            }
        }

        return $groups;
    }

    protected function matchesSearch(SpreadsheetTemplateDefinition $definition, SpreadsheetTemplateManager $manager): bool
    {
        $search = trim($this->search);

        if ($search === '') {
            return true;
        }

        $haystacks = [
            $definition->title,
            $definition->context,
            $definition->description,
            $definition->category,
            (string) $manager->fileName($definition),
        ];

        foreach ($haystacks as $haystack) {
            if (Str::contains($haystack, $search, ignoreCase: true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function card(SpreadsheetTemplateDefinition $definition, SpreadsheetTemplateManager $manager): array
    {
        $customized = $manager->isCustomized($definition);
        $pendingFile = $this->templateFiles[$definition->key] ?? null;
        $customizedAt = $manager->customizedAt($definition);

        $canDownload = $definition->canDownload()
            && $manager->exists($definition)
            && $this->canDownloadTemplate($definition);

        return [
            'key' => $definition->key,
            'property' => "templateFiles.{$definition->key}",
            'input_id' => "template-file-{$definition->key}",
            'title' => $definition->title,
            'category' => $definition->category,
            'context' => $definition->context,
            'description' => $customized ? $definition->customDescription : $definition->description,
            'file_name' => $manager->fileName($definition),
            'status_label' => $manager->statusLabel($definition),
            'status_classes' => $customized
                ? 'border border-amber-400/30 bg-amber-500/15 text-amber-100'
                : 'border border-emerald-400/30 bg-emerald-500/15 text-emerald-100',
            'is_custom' => $customized,
            'is_dynamic' => $definition->isDynamic(),
            'can_replace' => $definition->canReplace(),
            'can_restore' => $definition->canRestoreDefault(),
            'download_url' => $canDownload ? route($definition->downloadRoute) : null,
            'customized_at' => $customizedAt?->format('d/m/Y H:i'),
            'customized_by' => $manager->customizedBy($definition),
            'has_file' => $pendingFile !== null,
            'file_display_name' => is_object($pendingFile) && method_exists($pendingFile, 'getClientOriginalName')
                ? $pendingFile->getClientOriginalName()
                : 'Arquivo selecionado',
            'save_action' => "saveTemplate('{$definition->key}')",
            'restore_action' => "restoreDefaultTemplate('{$definition->key}')",
            'restore_confirmation' => $definition->restoreConfirmation,
        ];
    }

    /**
     * UI hint only; the download route performs the real authorization.
     */
    protected function canDownloadTemplate(SpreadsheetTemplateDefinition $definition): bool
    {
        if ($definition->downloadAbilities === []) {
            return true;
        }

        return auth()->user()?->canAny($definition->downloadAbilities) ?? false;
    }
}
