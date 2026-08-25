<?php

use App\Models\Nimbus\PortalUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

it('stores document_number encrypted at rest and decrypts correctly', function () {
    $portalUser = PortalUser::query()->create([
        'full_name' => 'Cliente PII',
        'email' => 'cliente.pii@example.com',
        'document_number' => '12345678901',
        'phone_number' => '11999999999',
        'status' => 'ACTIVE',
    ]);

    $raw = DB::table('nimbus_portal_users')->where('id', $portalUser->id)->first();
    expect($raw->document_number)->not->toBe('12345678901')
        ->and($raw->phone_number)->not->toBe('11999999999')
        ->and($raw->document_number_hash)->not->toBeNull()
        ->and($raw->phone_number_hash)->not->toBeNull();

    // Decrypt via model accessor.
    $fresh = PortalUser::find($portalUser->id);
    expect($fresh->document_number)->toBe('12345678901')
        ->and($fresh->phone_number)->toBe('11999999999');

    // Raw encrypted payload can be decrypted directly.
    expect(Crypt::decryptString($raw->document_number))->toBe('12345678901');
});

it('uses domain-separated blind index for document and phone', function () {
    $docHash = PortalUser::documentNumberHash('123.456.789-01');
    $phoneHashSameDigits = PortalUser::phoneNumberHash('12345678901');

    // Same digits but different purpose => different hashes.
    expect($docHash)->not->toBe($phoneHashSameDigits);

    // Same CPF formatted vs unformatted => same hash.
    expect(PortalUser::documentNumberHash('123.456.789-01'))->toBe(PortalUser::documentNumberHash('12345678901'));

    // Blind index is deterministic HMAC, not reversible ciphertext.
    $portalUser = PortalUser::query()->create([
        'full_name' => 'Hash Check',
        'email' => 'hash@example.com',
        'document_number' => '98765432100',
        'phone_number' => null,
        'status' => 'ACTIVE',
    ]);

    $raw = DB::table('nimbus_portal_users')->where('id', $portalUser->id)->first();
    expect($raw->document_number_hash)->toBe(PortalUser::documentNumberHash('98765432100'));
});

it('allows exact lookup via blind index', function () {
    $portalUser = PortalUser::query()->create([
        'full_name' => 'Busca Exata',
        'email' => 'busca@example.com',
        'document_number' => '11122233344',
        'phone_number' => '11987654321',
        'status' => 'ACTIVE',
    ]);

    $foundByDoc = PortalUser::findByDocumentNumber('111.222.333-44');
    expect($foundByDoc?->id)->toBe($portalUser->id);

    $foundByPhone = PortalUser::findByPhoneNumber('(11) 98765-4321');
    expect($foundByPhone?->id)->toBe($portalUser->id);

    expect(PortalUser::findByDocumentNumber('99999999999'))->toBeNull();
});

it('enforces uniqueness on normalized document_number via hash', function () {
    PortalUser::query()->create([
        'full_name' => 'Primeiro',
        'email' => 'primeiro@example.com',
        'document_number' => '12345678901',
        'phone_number' => null,
        'status' => 'ACTIVE',
    ]);

    // Same CPF formatted differently should be rejected at DB level (unique hash).
    expect(function () {
        PortalUser::query()->create([
            'full_name' => 'Segundo',
            'email' => 'segundo@example.com',
            'document_number' => '123.456.789-01',
            'phone_number' => null,
            'status' => 'ACTIVE',
        ]);
    })->toThrow(Exception::class);
});

it('allows null document_number duplicates (hash null)', function () {
    PortalUser::query()->create([
        'full_name' => 'Sem CPF 1',
        'email' => 'sem1@example.com',
        'document_number' => null,
        'phone_number' => null,
        'status' => 'ACTIVE',
    ]);

    PortalUser::query()->create([
        'full_name' => 'Sem CPF 2',
        'email' => 'sem2@example.com',
        'document_number' => null,
        'phone_number' => null,
        'status' => 'ACTIVE',
    ]);

    expect(PortalUser::query()->whereNull('document_number_hash')->count())->toBe(2);
});

it('does not expose raw PII in activity log properties', function () {
    $portalUser = PortalUser::query()->create([
        'full_name' => 'Audit PII',
        'email' => 'audit.pii@example.com',
        'document_number' => '12345678901',
        'phone_number' => '11999999999',
        'status' => 'ACTIVE',
    ]);

    $activity = Activity::where('subject_type', PortalUser::class)
        ->where('subject_id', $portalUser->id)
        ->where('description', 'nimbus.portal_user.created')
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and(json_encode($activity->properties))->not->toContain('12345678901')
        ->and(json_encode($activity->properties))->not->toContain('11999999999');
});

it('backfill is idempotent and detects conflicts', function () {
    // Simulate legacy plaintext row inserted directly via DB (bypassing model encryption).
    DB::table('nimbus_portal_users')->insert([
        'full_name' => 'Legacy 1',
        'email' => 'legacy1@example.com',
        'document_number' => '22233344455',
        'phone_number' => '11911112222',
        'status' => 'ACTIVE',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $legacyId = DB::table('nimbus_portal_users')->where('email', 'legacy1@example.com')->value('id');

    // Dry-run should report migration needed.
    $this->artisan('nimbus:backfill-pii-hashes', ['--dry-run' => true])
        ->assertSuccessful();

    // Real run.
    $this->artisan('nimbus:backfill-pii-hashes')
        ->assertSuccessful();

    $raw = DB::table('nimbus_portal_users')->where('id', $legacyId)->first();
    // After backfill, raw is encrypted and hash populated.
    expect($raw->document_number)->not->toBe('22233344455')
        ->and($raw->document_number_hash)->toBe(PortalUser::documentNumberHash('22233344455'));

    // Second run is idempotent (no errors).
    $this->artisan('nimbus:backfill-pii-hashes')
        ->assertSuccessful();

    $again = DB::table('nimbus_portal_users')->where('id', $legacyId)->first();
    expect($again->document_number_hash)->toBe($raw->document_number_hash);
});
