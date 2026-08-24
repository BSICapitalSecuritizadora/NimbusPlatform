<?php

namespace App\Models;

use App\Concerns\MoneyFormatter;
use App\Enums\ContractInstallmentStatus;
use App\Support\IdentifierNormalizer;
use Database\Factories\ContractInstallmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * One line of a contract's payment schedule.
 *
 * The contract is the only thing it points at. Client, unit, development and
 * emission are all reachable through it, so none of them is duplicated here --
 * which is what will let the contract grow a second buyer later without this
 * module changing at all.
 *
 * Status, saldo and days overdue are all derived: see
 * {@see ContractInstallmentStatus} for why none of them is a column.
 *
 * Soft deleted, and that is a different thing from cancelled. A cancelled
 * installment left the contractual flow and stays on screen; a deleted one was
 * created by mistake and leaves the operation, freeing its number for a new
 * import.
 */
class ContractInstallment extends Model
{
    /** @use HasFactory<ContractInstallmentFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'contract_id',
        'number',
        'due_date',
        'expected_value',
        'payment_date',
        'paid_value',
        'cancellation_date',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'payment_date' => 'date',
            'cancellation_date' => 'date',
            'expected_value' => 'decimal:2',
            'paid_value' => 'decimal:2',
        ];
    }

    /**
     * Every fillable attribute is a financial fact worth a trail: vencimento,
     * valor previsto, data e valor do pagamento, cancelamento. No personal data
     * passes through here -- the buyer lives on the contract.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * `number_normalized` is derived, never filled: it is written from `number`
     * on every save so the two cannot drift apart.
     *
     * A bulk `update()` on the query builder skips this, as it skips every model
     * event -- so write installments through the model. The one place that has
     * to bypass it is the spreadsheet import, and it sets the column explicitly
     * from the same function.
     */
    protected static function booted(): void
    {
        static::saving(function (self $installment): void {
            $installment->number_normalized = self::normalizeNumberForComparison($installment->number);
        });
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    protected function number(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => self::normalizeNumber($value),
        );
    }

    /**
     * The number exactly as the operator wrote it, minus the surrounding
     * whitespace. This is the presentation value; identity lives in
     * {@see normalizeNumberForComparison()}.
     */
    public static function normalizeNumber(?string $value): ?string
    {
        return IdentifierNormalizer::display($value);
    }

    /**
     * The identity of a number inside its contract: what "the same installment"
     * means. The rule itself lives in {@see IdentifierNormalizer}, shared with
     * {@see Contract::normalizeCodeForComparison()}, so the two identifications
     * this module depends on cannot drift apart.
     */
    public static function normalizeNumberForComparison(?string $value): ?string
    {
        return IdentifierNormalizer::identity($value);
    }

    /**
     * State of the installment right now. Recomputed on every read on purpose:
     * the only input that changes without anyone touching the row is today's
     * date, and that is exactly the input a stored status would get wrong.
     *
     * An accessor rather than a plain method, deliberately: `$installment->status`
     * as a bare method would make Eloquent try to resolve `status()` as a
     * relation the moment anything read it as a property.
     */
    public function getStatusAttribute(): ContractInstallmentStatus
    {
        if (filled($this->cancellation_date)) {
            return ContractInstallmentStatus::Cancelled;
        }

        if ($this->outstandingCents() === 0) {
            return ContractInstallmentStatus::Paid;
        }

        if (($this->due_date !== null) && $this->due_date->lt(today())) {
            return ContractInstallmentStatus::Overdue;
        }

        return $this->paidCents() > 0
            ? ContractInstallmentStatus::PartiallyPaid
            : ContractInstallmentStatus::Upcoming;
    }

    /**
     * What the installment still owes, floored at zero: a receipt carrying
     * juros, multa or correção lands above the expected value, and a negative
     * saldo would then poison every sum built on top of it.
     */
    public function getOutstandingValueAttribute(): float
    {
        return round($this->outstandingCents() / 100, 2);
    }

    /**
     * Days between the due date and today, for an installment that still owes
     * money and is already past due. Zero for everything else -- cancelled,
     * settled or still in time.
     *
     * Deliberately the *current* delay, not the historical delay of a receipt
     * booked after its due date. That one is computable from `payment_date` and
     * `due_date` whenever it is asked for; nothing needs to be stored for it.
     */
    public function getDaysOverdueAttribute(): int
    {
        if (! $this->status->hasOutstandingBalance() || ($this->due_date === null)) {
            return 0;
        }

        $today = today();

        return $this->due_date->lt($today) ? (int) $this->due_date->diffInDays($today) : 0;
    }

    public function getFormattedExpectedValueAttribute(): string
    {
        return 'R$ '.MoneyFormatter::formatCurrencyForDisplay($this->expected_value);
    }

    public function getFormattedPaidValueAttribute(): string
    {
        return 'R$ '.MoneyFormatter::formatCurrencyForDisplay($this->paid_value);
    }

    public function getFormattedOutstandingValueAttribute(): string
    {
        return 'R$ '.MoneyFormatter::formatCurrencyForDisplay($this->outstanding_value);
    }

    /**
     * Whether the number is already taken inside the contract. Scoped to the
     * contract, never global: every contract numbers its own schedule from 001.
     *
     * Compares the normalized identity, so "Entrada", "ENTRADA" and " entrada "
     * are one number regardless of what the database collation would have said.
     *
     * Soft deleted installments are ignored, matching the unique index: a row
     * created by mistake and deleted must not block the number forever.
     */
    public static function isDuplicateNumber(mixed $contractId, ?string $number, mixed $ignoreId = null): bool
    {
        $number = self::normalizeNumberForComparison($number);

        if (blank($contractId) || ($number === null)) {
            return false;
        }

        return self::query()
            ->where('contract_id', $contractId)
            ->where('number_normalized', $number)
            ->when($ignoreId, fn (Builder $query): Builder => $query->whereKeyNot($ignoreId))
            ->exists();
    }

    /**
     * Installments that still owe money: not cancelled, and not yet covered by
     * what was received. The comparison runs on the DECIMAL columns themselves,
     * so it is exact -- no float rounding sneaks into an inadimplência query.
     *
     * @param  Builder<ContractInstallment>  $query
     */
    public function scopeOutstanding(Builder $query): void
    {
        $query->whereNull('cancellation_date')
            ->whereRaw('COALESCE(paid_value, 0) < expected_value');
    }

    /**
     * The SQL twin of {@see getStatusAttribute()}. Every branch mirrors a branch there, so a
     * filter and a badge can never disagree about the same row.
     *
     * @param  Builder<ContractInstallment>  $query
     */
    public function scopeWithStatus(Builder $query, ContractInstallmentStatus $status): void
    {
        $today = today()->toDateString();

        match ($status) {
            ContractInstallmentStatus::Cancelled => $query->whereNotNull('cancellation_date'),

            ContractInstallmentStatus::Paid => $query->whereNull('cancellation_date')
                ->whereRaw('COALESCE(paid_value, 0) >= expected_value'),

            ContractInstallmentStatus::Overdue => $query->outstanding()
                ->where('due_date', '<', $today),

            ContractInstallmentStatus::PartiallyPaid => $query->outstanding()
                ->whereRaw('COALESCE(paid_value, 0) > 0')
                ->where('due_date', '>=', $today),

            ContractInstallmentStatus::Upcoming => $query->outstanding()
                ->whereRaw('COALESCE(paid_value, 0) = 0')
                ->where('due_date', '>=', $today),
        };
    }

    /**
     * @param  Builder<ContractInstallment>  $query
     */
    public function scopeForEmission(Builder $query, mixed $emissionId): void
    {
        $query->whereHas(
            'contract.construction',
            fn (Builder $constructionQuery): Builder => $constructionQuery->where('emission_id', $emissionId),
        );
    }

    /**
     * Free text search across the installment and the contract behind it.
     *
     * @param  Builder<ContractInstallment>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $query->where(function (Builder $query) use ($term): void {
            $query->where('number', 'like', "%{$term}%")
                ->orWhereHas('contract', fn (Builder $contractQuery): Builder => $contractQuery->search($term));
        });
    }

    /**
     * Money is compared in integer cents everywhere in this model. `decimal:2`
     * hands back a string, and casting two of those to float to compare them is
     * how "R$ 10.000,00 paid against R$ 10.000,00 expected" ends up one cent
     * short of settled.
     */
    private function expectedCents(): int
    {
        return self::toCents($this->expected_value);
    }

    private function paidCents(): int
    {
        return self::toCents($this->paid_value);
    }

    private function outstandingCents(): int
    {
        return max(0, $this->expectedCents() - $this->paidCents());
    }

    private static function toCents(mixed $value): int
    {
        return blank($value) ? 0 : (int) round(MoneyFormatter::normalizeDecimalValue($value) * 100);
    }
}
