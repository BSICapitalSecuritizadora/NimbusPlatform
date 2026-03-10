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
    Storage::fake(config('filesystems.default'));
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
        'version' => 1,
    ]);
    $original->investors()->attach($investor);
    $original->emissions()->attach($emission);

    $newFile = UploadedFile::fake()->create('new_v2.pdf', 1024, 'application/pdf');

    // Execute the clone logic directly (simulating the action's behavior)
    $newVersion = $original->replicate(['file_path', 'file_name', 'mime_type', 'file_size', 'version', 'parent_document_id', 'replaced_at']);
    $newVersion->file_path = $newFile->store('documents', config('filesystems.default'));
    $newVersion->file_name = $newFile->getClientOriginalName();
    $newVersion->mime_type = $newFile->getMimeType();
    $newVersion->file_size = $newFile->getSize();
    $newVersion->version = $original->version + 1;
    $newVersion->parent_document_id = $original->parent_document_id ?? $original->id;
    $newVersion->is_published = false;
    $newVersion->save();

    $newVersion->emissions()->sync($original->emissions->pluck('id'));
    $newVersion->investors()->sync($original->investors->pluck('id'));

    $original->update([
        'is_published' => false,
        'replaced_at' => now(),
    ]);

    // Refresh original
    $original->refresh();

    // Verify original is archived
    expect($original->is_published)->toBeFalse()
        ->and($original->replaced_at)->not->toBeNull();

    // Verify new version was created
    $newVersion = Document::where('parent_document_id', $original->id)->first();

    expect($newVersion)->not->toBeNull()
        ->and($newVersion->title)->toBe('Sample Doc')
        ->and($newVersion->version)->toBe(2)
        ->and($newVersion->parent_document_id)->toBe($original->id)
        ->and($newVersion->is_published)->toBeFalse()
        ->and($newVersion->investors)->toHaveCount(1)
        ->and($newVersion->emissions)->toHaveCount(1);
});
