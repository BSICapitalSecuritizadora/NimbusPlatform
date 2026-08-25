<?php

namespace App\Models\Nimbus;

use App\Casts\LegacyEncrypted;
use App\Services\Security\BlindIndexService;
use App\Services\Security\PiiPseudonymizer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PortalUser extends Authenticatable
{
    use HasFactory;

    protected $table = 'nimbus_portal_users';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'last_login_at' => 'datetime',
            'document_number' => LegacyEncrypted::class,
            'phone_number' => LegacyEncrypted::class,
        ];
    }

    /**
     * Domain-separated HMAC for blind indexes.
     * Delegates to centralized BlindIndexService for versioning/rotation.
     */
    public static function blindIndex(string $purpose, ?string $normalizedValue): ?string
    {
        return BlindIndexService::for($purpose, $normalizedValue);
    }

    public static function documentNumberHash(?string $rawValue): ?string
    {
        return BlindIndexService::documentNumber($rawValue);
    }

    public static function phoneNumberHash(?string $rawValue): ?string
    {
        return BlindIndexService::phoneNumber($rawValue);
    }

    /**
     * Find by exact document number using blind index (no plaintext LIKE).
     */
    public static function findByDocumentNumber(string $documentNumber): ?self
    {
        $hash = static::documentNumberHash($documentNumber);

        if ($hash === null) {
            return null;
        }

        return static::query()->where('document_number_hash', $hash)->first();
    }

    public static function findByPhoneNumber(string $phoneNumber): ?self
    {
        $hash = static::phoneNumberHash($phoneNumber);

        if ($hash === null) {
            return null;
        }

        return static::query()->where('phone_number_hash', $hash)->first();
    }

    protected static function booted(): void
    {
        static::created(function (self $model): void {
            // Avoid logging during factory/backfill where causer is null; still log for audit completeness.
            $causer = auth()->user();

            activity('nimbus')
                ->performedOn($model)
                ->causedBy($causer)
                ->withProperties([
                    'email_hash' => PiiPseudonymizer::email($model->email),
                    'document_hash' => PiiPseudonymizer::document($model->getAttribute('document_number')),
                    'status' => $model->status,
                ])
                ->log('nimbus.portal_user.created');
        });

        static::updated(function (self $model): void {
            $causer = auth()->user();
            $changed = array_keys($model->getChanges());
            // Filter to meaningful keys.
            $relevant = array_intersect($changed, ['full_name', 'email', 'status', 'document_number', 'phone_number', 'external_id']);
            // Always log status transitions (activation/blocking) even if other keys not relevant.
            if (empty($relevant) && ! $model->wasChanged('status')) {
                return;
            }

            activity('nimbus')
                ->performedOn($model)
                ->causedBy($causer)
                ->withProperties([
                    'changed_keys' => array_values($relevant),
                    'email_hash' => PiiPseudonymizer::email($model->email),
                    'status' => $model->status,
                ])
                ->log('nimbus.portal_user.updated');
        });

        static::saving(function (self $model): void {
            // Normalize hashes from the raw (decrypted) attributes.
            // getAttribute returns decrypted value for encrypted casts (LegacyEncrypted handles plaintext fallback).
            $doc = $model->getAttribute('document_number');
            $phone = $model->getAttribute('phone_number');

            $expectedDocHash = static::documentNumberHash($doc);
            $expectedPhoneHash = static::phoneNumberHash($phone);

            // Transitional duplicate detection: covers both already-hashed rows and legacy
            // plaintext rows where hash is still NULL. UNIQUE constraint on hash alone
            // does not catch legacy NULL case, so we check explicitly before write.
            if ($expectedDocHash !== null && ($model->isDirty('document_number') || empty($model->getAttribute('document_number_hash')))) {
                $conflict = static::findDuplicateDocumentOwner($expectedDocHash, $doc, $model->getKey());
                if ($conflict !== null) {
                    throw ValidationException::withMessages([
                        'document_number' => 'Este CPF já está cadastrado.',
                    ]);
                }
                $model->setAttribute('document_number_hash', $expectedDocHash);
            } elseif ($model->isDirty('document_number') || empty($model->getAttribute('document_number_hash'))) {
                $model->setAttribute('document_number_hash', $expectedDocHash);
            }

            if ($expectedPhoneHash !== null && ($model->isDirty('phone_number') || empty($model->getAttribute('phone_number_hash')))) {
                // Phone is not unique, but we still populate hash for exact lookup.
                $model->setAttribute('phone_number_hash', $expectedPhoneHash);
            } elseif ($model->isDirty('phone_number') || empty($model->getAttribute('phone_number_hash'))) {
                $model->setAttribute('phone_number_hash', $expectedPhoneHash);
            }

            // Ensure empty strings become null for nullable DB columns.
            if ($model->getAttribute('document_number') === '') {
                $model->setAttribute('document_number', null);
            }

            if ($model->getAttribute('phone_number') === '') {
                $model->setAttribute('phone_number', null);
            }
        });
    }

    public function accessTokens(): HasMany
    {
        return $this->hasMany(AccessToken::class, 'nimbus_portal_user_id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class, 'nimbus_portal_user_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(PortalDocument::class, 'nimbus_portal_user_id');
    }

    /**
     * Find existing owner of same normalized document, covering legacy plaintext
     * rows and stale APP_KEY-derived hashes during dedicated-key transition.
     * Returns conflicting model id or null.
     */
    protected static function findDuplicateDocumentOwner(?string $expectedHash, ?string $rawDoc, mixed $excludeId = null): ?int
    {
        if ($expectedHash === null) {
            return null;
        }

        // Fast path: rows already hashed with current dedicated key.
        $hashedConflict = static::query()
            ->where('document_number_hash', $expectedHash)
            ->when($excludeId !== null, fn ($q) => $q->where('id', '!=', $excludeId))
            ->value('id');

        if ($hashedConflict !== null) {
            return (int) $hashedConflict;
        }

        // Transitional: rows hashed with old APP_KEY-derived key (stale after dedicated-key migration).
        $legacyHash = BlindIndexService::legacyDocumentNumber($rawDoc);
        if ($legacyHash !== null && $legacyHash !== $expectedHash) {
            $legacyHashedConflict = static::query()
                ->where('document_number_hash', $legacyHash)
                ->when($excludeId !== null, fn ($q) => $q->where('id', '!=', $excludeId))
                ->value('id');

            if ($legacyHashedConflict !== null) {
                return (int) $legacyHashedConflict;
            }
        }

        // Transitional: legacy rows where hash is still NULL but plaintext holds same digits.
        $legacyRows = DB::table('nimbus_portal_users')
            ->whereNull('document_number_hash')
            ->whereNotNull('document_number')
            ->when($excludeId !== null, fn ($q) => $q->where('id', '!=', $excludeId))
            ->select('id', 'document_number')
            ->get();

        $normalizedIncoming = preg_replace('/\D+/', '', (string) $rawDoc);

        foreach ($legacyRows as $row) {
            $legacyDigits = preg_replace('/\D+/', '', (string) $row->document_number);

            if ($legacyDigits === $normalizedIncoming && $legacyDigits !== '') {
                return (int) $row->id;
            }
        }

        return null;
    }
}
