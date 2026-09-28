<?php

namespace App\Support\SpreadsheetTemplates;

use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Spatie\Activitylog\Models\Activity;

/**
 * Normalized operations over any registered spreadsheet template.
 *
 * Keeps the settings page free of per-template branching: status, effective
 * file name, replacement and restore all flow through the definition's
 * handler, which remains the single source of truth also used by the
 * importers and download routes.
 */
class SpreadsheetTemplateManager
{
    public const STATUS_CUSTOM = 'custom';

    public const STATUS_DEFAULT = 'default';

    public function isCustomized(SpreadsheetTemplateDefinition $definition): bool
    {
        $handler = $definition->handler();

        if (! $handler instanceof CustomizableSpreadsheetTemplate) {
            return false;
        }

        return $handler->hasCustomTemplate();
    }

    public function status(SpreadsheetTemplateDefinition $definition): string
    {
        return $this->isCustomized($definition) ? self::STATUS_CUSTOM : self::STATUS_DEFAULT;
    }

    public function statusLabel(SpreadsheetTemplateDefinition $definition): string
    {
        return $this->isCustomized($definition) ? 'Personalizado' : 'Padrão do sistema';
    }

    public function exists(SpreadsheetTemplateDefinition $definition): bool
    {
        $handler = $definition->handler();

        if ($handler instanceof CustomizableSpreadsheetTemplate) {
            return $handler->exists();
        }

        return true;
    }

    public function fileName(SpreadsheetTemplateDefinition $definition): ?string
    {
        $handler = $definition->handler();

        if ($handler instanceof CustomizableSpreadsheetTemplate || $handler instanceof GeneratedSpreadsheetTemplate) {
            return $handler->downloadName();
        }

        return null;
    }

    public function customizedAt(SpreadsheetTemplateDefinition $definition): ?CarbonImmutable
    {
        $handler = $definition->handler();

        if (! $handler instanceof CustomizableSpreadsheetTemplate) {
            return null;
        }

        return $handler->customTemplateUpdatedAt();
    }

    public function customizedBy(SpreadsheetTemplateDefinition $definition): ?string
    {
        if (! $this->isCustomized($definition)) {
            return null;
        }

        $activity = Activity::inLog('spreadsheet_template')
            ->where('properties->template_key', $definition->key)
            ->where('description', 'spreadsheet_template_customized')
            ->latest('id')
            ->first();

        return $activity?->causer?->name;
    }

    public function store(SpreadsheetTemplateDefinition $definition, UploadedFile $file): void
    {
        abort_if(! $definition->canReplace(), 403);

        $handler = $definition->handler();

        abort_unless($handler instanceof CustomizableSpreadsheetTemplate, 403);

        $handler->store($file);

        activity('spreadsheet_template')
            ->causedBy(auth()->user())
            ->withProperties([
                'template_key' => $definition->key,
                'template_name' => $definition->title,
                'file_name' => $file->getClientOriginalName(),
                'file_size' => $file->getSize(),
            ])
            ->log('spreadsheet_template_customized');
    }

    public function restoreDefault(SpreadsheetTemplateDefinition $definition): void
    {
        abort_if(! $definition->canRestoreDefault(), 403);

        $handler = $definition->handler();

        abort_unless($handler instanceof CustomizableSpreadsheetTemplate, 403);

        $handler->restoreDefault();

        activity('spreadsheet_template')
            ->causedBy(auth()->user())
            ->withProperties([
                'template_key' => $definition->key,
                'template_name' => $definition->title,
            ])
            ->log('spreadsheet_template_restored');
    }
}
