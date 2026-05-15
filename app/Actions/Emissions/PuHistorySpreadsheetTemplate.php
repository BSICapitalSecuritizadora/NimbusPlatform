<?php

namespace App\Actions\Emissions;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class PuHistorySpreadsheetTemplate
{
    public const DOWNLOAD_NAME = "Template - Hist\u{00F3}rico de PU.xlsx";

    protected const CUSTOM_TEMPLATE_DISK = 'local';

    protected const CUSTOM_TEMPLATE_PATH = 'pu-history-templates/template-historico-de-pu.xlsx';

    protected const DEFAULT_TEMPLATE_PATH = 'resources/templates/pu-histories/template-historico-de-pu.xlsx';

    public function exists(): bool
    {
        return $this->resolvePath() !== null;
    }

    public function hasCustomTemplate(): bool
    {
        return Storage::disk(self::CUSTOM_TEMPLATE_DISK)->exists(self::CUSTOM_TEMPLATE_PATH);
    }

    public function resolvePath(): ?string
    {
        if ($this->hasCustomTemplate()) {
            return Storage::disk(self::CUSTOM_TEMPLATE_DISK)->path(self::CUSTOM_TEMPLATE_PATH);
        }

        $defaultPath = base_path(self::DEFAULT_TEMPLATE_PATH);

        return is_file($defaultPath) ? $defaultPath : null;
    }

    public function downloadName(): string
    {
        return self::DOWNLOAD_NAME;
    }

    public function store(UploadedFile $file): void
    {
        Storage::disk(self::CUSTOM_TEMPLATE_DISK)->putFileAs(
            dirname(self::CUSTOM_TEMPLATE_PATH),
            $file,
            basename(self::CUSTOM_TEMPLATE_PATH),
        );
    }

    public function restoreDefault(): void
    {
        Storage::disk(self::CUSTOM_TEMPLATE_DISK)->delete(self::CUSTOM_TEMPLATE_PATH);
    }
}
