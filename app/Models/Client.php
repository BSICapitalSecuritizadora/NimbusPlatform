<?php

namespace App\Models;

use App\Enums\ClientPersonType;
use App\Services\Security\PiiPseudonymizer;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\Contracts\Activity;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Buyer of construction units.
 *
 * Independent from Investor (CRI portal user) and from ProposalCompany
 * (commercial funnel), and deliberately without any relation to units: that link
 * belongs to {@see Contract}, which is what preserves the commercial history.
 *
 * Soft deleted so a client stays the same record -- and the same id -- across
 * the whole contractual history.
 */
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'person_type',
        'name',
        'trade_name',
        'document',
        'email',
        'phone',
    ];

    protected function casts(): array
    {
        return [
            'person_type' => ClientPersonType::class,
        ];
    }

    /**
     * The document is personal data: it is never written to the activity log in
     * full. A stable pseudonym is recorded instead, so a change can be audited
     * and correlated without storing the CPF/CNPJ.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logExcept(['document'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function tapActivity(Activity $activity): void
    {
        $properties = $activity->properties ?? collect();

        $activity->properties = $properties->put('document_hash', PiiPseudonymizer::document($this->document));
    }

    protected function document(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => self::normalizeDocument($value),
        );
    }

    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => blank($value) ? null : Str::lower(trim($value)),
        );
    }

    protected function phone(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => blank($value) ? null : (Str::digitsOnly($value) ?: null),
        );
    }

    public static function normalizeDocument(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = Str::digitsOnly($value);

        return $digits === '' ? null : $digits;
    }

    /**
     * Every sale ever made to this client, across units and developments.
     *
     * Through the buyer table: a sale can have more than one buyer, and this
     * client is one of them.
     *
     * @return BelongsToMany<Contract, $this>
     */
    public function contracts(): BelongsToMany
    {
        return $this->belongsToMany(Contract::class, 'contract_clients');
    }

    public function getFormattedDocumentAttribute(): string
    {
        return self::formatDocument($this->document);
    }

    public function getMaskedDocumentAttribute(): string
    {
        return self::maskDocument($this->document);
    }

    /**
     * Partially hidden document, for screens that only need to tell two clients
     * apart -- pickers, listings, summaries. The full value stays available
     * through {@see formatDocument()} for the pages that genuinely need it.
     */
    public static function maskDocument(?string $value): string
    {
        $digits = self::normalizeDocument($value) ?? '';

        if (strlen($digits) === 11) {
            return '***.***.***-'.substr($digits, 9, 2);
        }

        if (strlen($digits) === 14) {
            return '**.***.***/'.substr($digits, 8, 4).'-'.substr($digits, 12, 2);
        }

        return $digits === '' ? '' : str_repeat('*', strlen($digits));
    }

    /**
     * Display formatting inferred from the document length, so it works for both
     * person types without needing the type at hand.
     */
    public static function formatDocument(?string $value): string
    {
        $digits = self::normalizeDocument($value) ?? '';

        if (strlen($digits) === 11) {
            return substr($digits, 0, 3).'.'.substr($digits, 3, 3).'.'.substr($digits, 6, 3).'-'.substr($digits, 9, 2);
        }

        if (strlen($digits) === 14) {
            return ExpenseServiceProvider::formatCnpj($digits);
        }

        return $digits;
    }

    public function getFormattedPhoneAttribute(): string
    {
        return self::formatPhone($this->phone);
    }

    public static function formatPhone(?string $value): string
    {
        $digits = Str::digitsOnly((string) $value);

        return match (strlen($digits)) {
            10 => sprintf('(%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 4), substr($digits, 6)),
            11 => sprintf('(%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 5), substr($digits, 7)),
            default => $digits,
        };
    }

    /**
     * Existing client holding the document, including soft deleted ones, so the
     * same person is never registered twice.
     */
    public static function findByDocument(?string $document, mixed $ignoreId = null): ?self
    {
        $document = self::normalizeDocument($document);

        if ($document === null) {
            return null;
        }

        return self::withTrashed()
            ->where('document', $document)
            ->when($ignoreId, fn (Builder $query): Builder => $query->whereKeyNot($ignoreId))
            ->first();
    }

    /**
     * @param  Builder<Client>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $digits = Str::digitsOnly($term);

        $query->where(function (Builder $query) use ($term, $digits): void {
            $query->where('name', 'like', "%{$term}%")
                ->orWhere('trade_name', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%");

            if ($digits !== '') {
                $query->orWhere('document', 'like', "%{$digits}%")
                    ->orWhere('phone', 'like', "%{$digits}%");
            }
        });
    }
}
