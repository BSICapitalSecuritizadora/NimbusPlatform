<?php

namespace App\Support\SpreadsheetTemplates;

use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;

/**
 * Contract for spreadsheet templates backed by a static file that can be
 * customized through the "Templates de Planilhas" settings page.
 *
 * The handler owns both sources of truth: the versioned default shipped with
 * the codebase and the optional custom file stored on disk. Importers and
 * download routes must resolve the effective file through here so the
 * settings page never drifts from what the import flow actually serves.
 */
interface CustomizableSpreadsheetTemplate
{
    public function exists(): bool;

    public function hasCustomTemplate(): bool;

    public function downloadName(): string;

    public function store(UploadedFile $file): void;

    public function restoreDefault(): void;

    /**
     * Last-modified instant of the custom file, or null when the system
     * default is in effect.
     */
    public function customTemplateUpdatedAt(): ?CarbonImmutable;
}
