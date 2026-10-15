<?php

namespace App\Models;

use App\Support\BankAccountNormalizer;
use Database\Factories\BankAccountFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A physical bank account shared across emissions.
 *
 * Phase 1A foundation: global identity only. No balances, no allocation and
 * no Conta Azul behavior live here yet; the legacy fund columns keep serving
 * every financial reader until a later phase migrates them.
 */
class BankAccount extends Model
{
    /** @use HasFactory<BankAccountFactory> */
    use HasFactory, LogsActivity;

    protected $fillable = [
        'bank_id',
        'agency_number',
        'agency_digit',
        'account_number',
        'account_digit',
        'display_agency',
        'display_account',
        'holder_context',
        'conta_azul_account_id',
        'notes',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected static function booted(): void
    {
        static::saving(function (BankAccount $account): void {
            if (is_string($account->agency_digit)) {
                $account->agency_digit = mb_strtoupper($account->agency_digit);
            }

            if (is_string($account->account_digit)) {
                $account->account_digit = mb_strtoupper($account->account_digit);
            }

            // Canonical parts are always validated (formatAgency/formatAccount
            // throw on anything non-canonical); the display columns are only
            // filled when blank, so an explicit display value never disables
            // validation of the stored parts.
            if (is_string($account->agency_number) && is_string($account->agency_digit)) {
                $formattedAgency = BankAccountNormalizer::formatAgency($account->agency_number, $account->agency_digit);

                if (blank($account->display_agency)) {
                    $account->display_agency = $formattedAgency;
                }
            }

            if (is_string($account->account_number) && is_string($account->account_digit)) {
                $formattedAccount = BankAccountNormalizer::formatAccount($account->account_number, $account->account_digit);

                if (blank($account->display_account)) {
                    $account->display_account = $formattedAccount;
                }
            }
        });
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    public function bankApplications(): HasMany
    {
        return $this->hasMany(BankApplication::class);
    }

    public function funds(): HasMany
    {
        return $this->hasMany(Fund::class);
    }

    /**
     * Phase 3A staging batches fetched through this account.
     *
     * Read-only navigation for the future reconciliation workflow; the
     * ingestion pipeline writes these rows directly.
     */
    public function contaAzulImportBatches(): HasMany
    {
        return $this->hasMany(ContaAzulImportBatch::class);
    }

    /**
     * Bills first seen through this account.
     *
     * Staging parentage, not ownership: a bill fetched through a shared
     * account is NOT attributed to any Emission by this relation.
     */
    public function contaAzulImportedBills(): HasMany
    {
        return $this->hasMany(ContaAzulImportedBill::class);
    }

    /**
     * Display form of the agency ("1234-5"), derived when not stored.
     */
    public function formattedAgency(): string
    {
        if (filled($this->display_agency)) {
            return (string) $this->display_agency;
        }

        return BankAccountNormalizer::formatAgency((string) $this->agency_number, (string) $this->agency_digit);
    }

    /**
     * Display form of the account ("0012345-6"), derived when not stored.
     */
    public function formattedAccount(): string
    {
        if (filled($this->display_account)) {
            return (string) $this->display_account;
        }

        return BankAccountNormalizer::formatAccount((string) $this->account_number, (string) $this->account_digit);
    }

    /**
     * Deterministic grouping key mirroring the canonical unique constraint.
     */
    public function canonicalKey(): string
    {
        return BankAccountNormalizer::canonicalKey(
            (int) $this->bank_id,
            (string) $this->agency_number,
            (string) $this->agency_digit,
            (string) $this->account_number,
            (string) $this->account_digit
        );
    }

    /**
     * Human-readable option label: bank plus canonical agency/account.
     */
    public function optionLabel(): string
    {
        $bank = $this->relationLoaded('bank') && $this->bank instanceof Bank
            ? $this->bank->name
            : Bank::query()->whereKey($this->bank_id)->value('name');

        return sprintf('%s · Ag %s · Ct %s', $bank ?? 'Banco', $this->formattedAgency(), $this->formattedAccount());
    }

    /**
     * Every fund touching this account, directly or via its applications.
     *
     * A fund linked through an application normally repeats the account id,
     * but the composite foreign key allows a NULL account side, so both
     * paths are queried and duplicates collapse with distinct().
     */
    public function linkedFundQuery(): Builder
    {
        $applicationIds = $this->bankApplications()->pluck('bank_applications.id');

        return Fund::query()
            ->where(function ($query) use ($applicationIds): void {
                $query->where('bank_account_id', $this->getKey());

                if ($applicationIds->isNotEmpty()) {
                    $query->orWhereIn('bank_application_id', $applicationIds);
                }
            });
    }

    /**
     * Distinct emissions across every fund linked to this account.
     *
     * Sharing an account across emissions is expected and must NOT be
     * presented as a shared application; only BankApplication::isShared()
     * carries the "Compartilhado" meaning.
     *
     * @return Collection<int, Emission>
     */
    public function linkedEmissions(): Collection
    {
        $emissionIds = (clone $this->linkedFundQuery())
            ->distinct()
            ->orderBy('emission_id')
            ->pluck('emission_id');

        if ($emissionIds->isEmpty()) {
            return collect();
        }

        return Emission::query()
            ->whereIn('id', $emissionIds)
            ->orderBy('name')
            ->get(['id', 'name']);
    }
}
