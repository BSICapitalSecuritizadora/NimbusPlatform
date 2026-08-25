<?php

namespace App\Models\Nimbus;

use App\Services\Security\PiiPseudonymizer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

class PortalUser extends Authenticatable
{
    use HasFactory;

    protected $table = 'nimbus_portal_users';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'last_login_at' => 'datetime',
            'document_number' => 'encrypted',
            'phone_number' => 'encrypted',
        ];
    }

    /**
     * Domain-separated HMAC for blind indexes.
     * Purpose strings ensure document_number and phone_number hashes are not interchangeable.
     */
    public static function blindIndex(string $purpose, ?string $normalizedValue): ?string
    {
        $normalized = trim((string) $normalizedValue);

        if ($normalized === '') {
            return null;
        }

        // Domain separation: purpose binds hash to its field.
        // Use APP_KEY as HMAC secret; key is never exposed.
        $keyMaterial = (string) config('app.key');
        $context = "nimbus:pii:{$purpose}:v1";

        return hash_hmac('sha256', $normalized, $keyMaterial.$context);
    }

    public static function documentNumberHash(?string $rawValue): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $rawValue);

        if ($digits === '' || $digits === null) {
            return null;
        }

        return static::blindIndex('document_number', $digits);
    }

    public static function phoneNumberHash(?string $rawValue): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $rawValue);

        if ($digits === '' || $digits === null) {
            return null;
        }

        return static::blindIndex('phone_number', $digits);
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
            // getAttribute returns decrypted value for encrypted casts.
            $doc = $model->getAttribute('document_number');
            $phone = $model->getAttribute('phone_number');

            // If the model has raw hash already set (e.g. backfill), respect it unless value changed.
            // We recompute hash whenever document_number or phone_number is dirty or hash is empty.
            if ($model->isDirty('document_number') || empty($model->getAttribute('document_number_hash'))) {
                $model->setAttribute('document_number_hash', static::documentNumberHash($doc));
            }

            if ($model->isDirty('phone_number') || empty($model->getAttribute('phone_number_hash'))) {
                $model->setAttribute('phone_number_hash', static::phoneNumberHash($phone));
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
}
