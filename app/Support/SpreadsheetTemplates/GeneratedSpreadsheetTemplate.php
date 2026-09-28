<?php

namespace App\Support\SpreadsheetTemplates;

/**
 * Contract for spreadsheet templates generated dynamically at download time
 * (e.g. from column definitions). They always reflect the current importer
 * schema, so they cannot be replaced manually.
 */
interface GeneratedSpreadsheetTemplate
{
    /**
     * Writes the template to a temporary file and returns its path.
     */
    public function build(): string;

    public function downloadName(): string;
}
