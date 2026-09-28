<?php

namespace App\Support\SpreadsheetTemplates;

use Illuminate\Support\Facades\Route;

/**
 * Metadata describing one spreadsheet template managed on the
 * "Templates de Planilhas" settings page.
 *
 * Definitions are registered once in SpreadsheetTemplateServiceProvider (or
 * from any module service provider) and the settings page renders purely
 * from the registry, so new templates appear without touching page code.
 */
final class SpreadsheetTemplateDefinition
{
    /**
     * @param  class-string  $handler  Template handler: a CustomizableSpreadsheetTemplate
     *                                 or GeneratedSpreadsheetTemplate implementation.
     * @param  list<string>  $downloadAbilities  Abilities accepted by the download
     *                                           route (user needs any of them). Used only as a UI
     *                                           hint; the route itself enforces authorization.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $title,
        public readonly string $category,
        public readonly string $context,
        public readonly string $description,
        public readonly string $downloadRoute,
        public readonly string $handler,
        public readonly bool $replaceable = false,
        public readonly bool $dynamic = false,
        public readonly array $downloadAbilities = [],
        public readonly string $customDescription = 'O arquivo atual foi enviado manualmente nesta área de templates.',
        public readonly string $restoreConfirmation = '',
        public readonly string $customizedNotificationBody = 'O novo arquivo já está disponível para download.',
        public readonly string $restoredNotificationBody = 'O template padrão do sistema voltou a ser usado.',
    ) {}

    public function canDownload(): bool
    {
        return $this->downloadRoute !== '' && Route::has($this->downloadRoute);
    }

    public function canReplace(): bool
    {
        return $this->replaceable;
    }

    public function canRestoreDefault(): bool
    {
        return $this->replaceable;
    }

    public function isDynamic(): bool
    {
        return $this->dynamic;
    }

    /**
     * Resolve the template handler from the container.
     *
     * @return CustomizableSpreadsheetTemplate|GeneratedSpreadsheetTemplate|object
     */
    public function handler(): object
    {
        return app($this->handler);
    }
}
