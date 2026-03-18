<?php

use App\Models\Document;
use App\Models\Emission;
use App\Models\Investor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake(Document::defaultStorageDisk());
});

it('can create a new document version via action', function () {
    \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'documents.update', 'guard_name' => 'web']);
    $admin = User::factory()->create();
    $admin->givePermissionTo('documents.update');

    $investor = Investor::factory()->create();
    $emission = Emission::factory()->create();

    $original = Document::factory()->published()->create([
        'title' => 'Sample Doc',
        'file_path' => 'documents/original.pdf',
        'storage_disk' => Document::defaultStorageDisk(),
        'version' => 1,
    ]);
    $original->investors()->attach($investor);
    $original->emissions()->attach($emission);

    $newFile = UploadedFile::fake()->create('new_v2.pdf', 1024, 'application/pdf');

    $newVersion = $original->replicate([
        'file_path',
        'file_name',
        'mime_type',
        'file_size',
        'storage_disk',
        'version',
        'parent_document_id',
        'replaced_at',
        'published_at',
        'published_by',
    ]);
    $newVersion->file_path = $newFile->store('documents', Document::defaultStorageDisk());
    $newVersion->file_name = $newFile->getClientOriginalName();
    $newVersion->mime_type = $newFile->getMimeType();
    $newVersion->file_size = $newFile->getSize();
    $newVersion->storage_disk = Document::defaultStorageDisk();
    $newVersion->version = $original->version + 1;
    $newVersion->parent_document_id = $original->parent_document_id ?? $original->id;
    $newVersion->is_published = false;
    $newVersion->published_at = null;
    $newVersion->published_by = null;
    $newVersion->save();

    $newVersion->emissions()->sync($original->emissions->pluck('id'));
    $newVersion->investors()->sync($original->investors->pluck('id'));

    $original->update([
        'is_published' => false,
        'replaced_at' => now(),
    ]);

    $original->refresh();

    expect($original->is_published)->toBeFalse()
        ->and($original->replaced_at)->not->toBeNull();

    $newVersion = Document::where('parent_document_id', $original->id)->first();

    expect($newVersion)->not->toBeNull()
        ->and($newVersion->title)->toBe('Sample Doc')
        ->and($newVersion->version)->toBe(2)
        ->and($newVersion->parent_document_id)->toBe($original->id)
        ->and($newVersion->is_published)->toBeFalse()
        ->and($newVersion->storage_disk)->toBe(Document::defaultStorageDisk())
        ->and($newVersion->published_at)->toBeNull()
        ->and($newVersion->published_by)->toBeNull()
        ->and($newVersion->investors)->toHaveCount(1)
        ->and($newVersion->emissions)->toHaveCount(1);
});
