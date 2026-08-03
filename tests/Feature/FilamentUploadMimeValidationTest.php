<?php

use App\Filament\Resources\Banks\Schemas\BankForm;
use App\Filament\Resources\Documents\Pages\ListDocuments;
use App\Filament\Resources\Documents\Tables\DocumentsTable;
use App\Filament\Resources\Emissions\Pages\CreateEmission;
use App\Filament\Resources\Emissions\Schemas\EmissionForm;
use App\Filament\Resources\Measurements\Pages\CreateMeasurement;
use App\Filament\Resources\Measurements\Pages\ViewMeasurement;
use App\Filament\Resources\Measurements\Schemas\MeasurementForm;
use App\Filament\Resources\Receivables\Pages\ListReceivables;
use App\Models\Emission;
use App\Models\Measurement;
use App\Models\MeasurementPayment;
use App\Models\Operation;
use App\Models\Receivable;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

uses(RefreshDatabase::class);

it('rejects HTML and SVG files from the vulnerable Filament uploads', function () {
    Storage::fake('tmp-for-tests');

    foreach (filamentUploadsWithMimeAllowlists() as $context => $upload) {
        expect($upload['field']->getAcceptedFileTypes())
            ->toBe(config($upload['config']), "The {$context} upload must use {$upload['config']}.");

        foreach (disallowedUploadPayloads() as $filename => $contents) {
            $file = temporaryUploadWithContent($filename, $contents);
            $validator = validator(
                ['upload' => [$file]],
                ['upload' => $upload['field']->getValidationRules()],
            );

            expect($validator->fails())
                ->toBeTrue("The {$context} upload accepted {$filename}.")
                ->and($validator->errors()->has('upload'))
                ->toBeTrue("The {$context} upload did not return a validation error for {$filename}.");
        }
    }
});

/**
 * @return array<string, array{field: FileUpload, config: string}>
 */
function filamentUploadsWithMimeAllowlists(): array
{
    $bankLogo = collect(BankForm::fields())
        ->first(fn (mixed $field): bool => $field instanceof FileUpload && $field->getName() === 'logo_path');

    $emissionSchema = EmissionForm::configure(
        Schema::make(new CreateEmission)->model(Emission::class),
    );
    $emissionLogo = $emissionSchema->getFlatFields(withHidden: true)['logo_path'];

    $measurementSchema = MeasurementForm::configure(
        Schema::make(new CreateMeasurement)->model(Measurement::class),
    );
    $measurementAssets = $measurementSchema->getFlatFields(withHidden: true)['assets'];
    $measurementFile = $measurementAssets instanceof Repeater
        ? $measurementAssets->getChildSchema()->getFlatFields(withHidden: true)['storage_path']
        : null;

    $receivablesPage = new ListReceivables;
    $headerActionsMethod = new ReflectionMethod($receivablesPage, 'getHeaderActions');
    $headerActionsMethod->setAccessible(true);
    $importAction = collect($headerActionsMethod->invoke($receivablesPage))
        ->first(fn (Action $action): bool => $action->getName() === 'import');
    $receivablesFile = $importAction?->getSchema(
        Schema::make($receivablesPage)->model(Receivable::class),
    )?->getFlatFields(withHidden: true)['file'] ?? null;

    $documentsPage = new ListDocuments;
    $documentsTable = DocumentsTable::configure(Table::make($documentsPage));
    $newVersionAction = $documentsTable->getAction('new_version');
    $documentFile = $newVersionAction?->getSchema(
        Schema::make($documentsPage),
    )?->getFlatFields(withHidden: true)['file_path'] ?? null;

    $operation = Operation::factory()->create();
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->id,
        'status' => 'awaiting_receipt',
    ]);
    MeasurementPayment::factory()->create([
        'operation_id' => $operation->id,
        'measurement_id' => $measurement->id,
        'receipt_path' => null,
    ]);

    $measurementPage = new ViewMeasurement;
    $measurementPage->record = $measurement;
    $receiptSchemaMethod = new ReflectionMethod($measurementPage, 'attachReceiptSchema');
    $receiptSchemaMethod->setAccessible(true);
    $receiptSchema = Schema::make($measurementPage)
        ->model($measurement)
        ->components($receiptSchemaMethod->invoke($measurementPage));
    $receipts = $receiptSchema->getFlatFields(withHidden: true)['receipts'];
    $receiptFile = $receipts instanceof Repeater
        ? $receipts->getChildSchema()->getFlatFields(withHidden: true)['receipt']
        : null;

    expect($bankLogo)->toBeInstanceOf(FileUpload::class)
        ->and($emissionLogo)->toBeInstanceOf(FileUpload::class)
        ->and($measurementFile)->toBeInstanceOf(FileUpload::class)
        ->and($receivablesFile)->toBeInstanceOf(FileUpload::class)
        ->and($documentFile)->toBeInstanceOf(FileUpload::class)
        ->and($receiptFile)->toBeInstanceOf(FileUpload::class);

    return [
        'receivables spreadsheet' => [
            'field' => $receivablesFile,
            'config' => 'uploads.receivables_import.allowed_mimes',
        ],
        'measurement file' => [
            'field' => $measurementFile,
            'config' => 'uploads.measurement.allowed_mimes',
        ],
        'measurement receipt' => [
            'field' => $receiptFile,
            'config' => 'uploads.measurement_receipt.allowed_mimes',
        ],
        'public document version' => [
            'field' => $documentFile,
            'config' => 'uploads.document.allowed_mimes',
        ],
        'emission logo' => [
            'field' => $emissionLogo,
            'config' => 'uploads.logo.allowed_mimes',
        ],
        'bank logo' => [
            'field' => $bankLogo,
            'config' => 'uploads.logo.allowed_mimes',
        ],
    ];
}

/**
 * @return array<string, string>
 */
function disallowedUploadPayloads(): array
{
    return [
        'payload.html' => '<!doctype html><html><body>unsafe</body></html>',
        'payload.svg' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
    ];
}

function temporaryUploadWithContent(string $filename, string $contents): TemporaryUploadedFile
{
    $uploadedFile = UploadedFile::fake()->createWithContent($filename, $contents);
    $storedPath = FileUploadConfiguration::storeTemporaryFile(
        $uploadedFile,
        FileUploadConfiguration::disk(),
    );

    return TemporaryUploadedFile::createFromLivewire(basename($storedPath));
}
