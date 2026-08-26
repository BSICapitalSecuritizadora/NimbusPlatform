<?php

namespace App\Models;

use App\Concerns\MoneyFormatter;
use App\Enums\ContractStatus;
use App\Support\Contracts\ContractOccupancyPeriod;
use App\Support\Contracts\ContractOccupancyTimeline;
use App\Support\IdentifierNormalizer;
use App\Support\Reconciliation\ValueComparator;
use Database\Factories\ContractFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * The commercial relationship between a client and a construction unit.
 *
 * Not to be confused with {@see LegalInstrument}, which is the legal paperwork
 * of an emission (CCB, termo de securitização). This is the sale itself.
 *
 * A resale never edits an existing contract: the previous one is distratado and
 * a new one is opened, so the history of a unit is the list of its contracts.
 *
 * Soft deleted because a contract is commercial history; the unit and the client
 * it points at can never be removed while it exists.
 */
class Contract extends Model
{
    /** @use HasFactory<ContractFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    /**
     * `construction_id` is absent on purpose: it is derived from the unit on
     * every save, never filled from a form, so it cannot drift.
     *
     * @var list<string>
     */
    protected $fillable = [
        'construction_unit_id',
        'code',
        'sale_date',
        'sale_value',
        'status',
        'cancellation_date',
    ];

    /**
     * @var array{expected: float, paid: float, outstanding: float, difference: float, count: int}|null
     */
    private ?array $memoizedInstallmentsSummary = null;

    protected function casts(): array
    {
        return [
            'sale_date' => 'date',
            'cancellation_date' => 'date',
            'sale_value' => 'decimal:2',
            'status' => ContractStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $contract): void {
            $contract->construction_id = self::resolveConstructionId($contract->construction_unit_id);

            /**
             * Derived, never filled from a form. A bulk `update()` on the query
             * builder skips this as it skips every model event -- the contract
             * import is the one place that bypasses it, and it writes the column
             * explicitly from the same function.
             */
            $contract->code_normalized = self::normalizeCodeForComparison($contract->code);

            if (! ($contract->status instanceof ContractStatus) || ! $contract->status->requiresCancellationDate()) {
                $contract->cancellation_date = null;
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * Everyone who bought under this contract.
     *
     * The source of truth for the commercial relationship. `contracts.client_id`
     * still exists in the schema but nothing reads or writes it any more: it is
     * legacy waiting to be dropped, not a buyer.
     *
     * `withTrashed()` on purpose: a buyer archived years later is still who
     * signed, and a historical contract that hid them would be lying. Refusing
     * to give a *new* contract to an archived client is a rule of the write
     * path, not of reading the past.
     *
     * @return BelongsToMany<Client, $this>
     */
    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(Client::class, 'contract_clients')
            ->withTrashed()
            ->orderBy('clients.name');
    }

    public function constructionUnit(): BelongsTo
    {
        return $this->belongsTo(ConstructionUnit::class);
    }

    /**
     * Denormalized from the unit. Read it freely; never write it by hand.
     */
    public function construction(): BelongsTo
    {
        return $this->belongsTo(Construction::class);
    }

    /**
     * The payment schedule of the sale. Ordered by due date rather than by
     * number: an installment is identified by text, so "010" would sort before
     * "2" and "ENTRADA" would land wherever the alphabet put it.
     */
    public function installments(): HasMany
    {
        return $this->hasMany(ContractInstallment::class)
            ->orderBy('due_date')
            ->orderBy('number');
    }

    /**
     * Totals of the schedule, in one aggregate query, memoized for the several
     * entries of the contract page that read it.
     *
     * Cancelled installments are left out of every total: they are no longer
     * part of the contractual flow. Soft deleted ones are excluded by the
     * relation itself.
     *
     * `difference` is a conference indicator, never a rule. A schedule that does
     * not add up to the sale value is routine -- entrada paid before the
     * contract, descontos, reforços, correção, installments not imported yet --
     * so it is shown and nothing is blocked or corrected because of it.
     *
     * @return array{expected: float, paid: float, outstanding: float, difference: float, count: int}
     */
    public function installmentsSummary(): array
    {
        if ($this->memoizedInstallmentsSummary !== null) {
            return $this->memoizedInstallmentsSummary;
        }

        $totals = $this->installments()
            ->whereNull('cancellation_date')
            ->selectRaw('COUNT(*) as installments_count')
            ->selectRaw('COALESCE(SUM(expected_value), 0) as expected_total')
            ->selectRaw('COALESCE(SUM(paid_value), 0) as paid_total')
            ->first();

        $expected = round((float) ($totals->expected_total ?? 0), 2);
        $paid = round((float) ($totals->paid_total ?? 0), 2);

        return $this->memoizedInstallmentsSummary = [
            'count' => (int) ($totals->installments_count ?? 0),
            'expected' => $expected,
            'paid' => $paid,
            'outstanding' => round(max(0, $expected - $paid), 2),
            'difference' => round($expected - (float) $this->sale_value, 2),
        ];
    }

    protected function code(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => self::normalizeCode($value),
        );
    }

    /**
     * The code exactly as the incorporadora writes it, minus the surrounding
     * whitespace. This is the presentation value -- what the paperwork says and
     * what every screen shows back. Identity lives in
     * {@see normalizeCodeForComparison()}.
     */
    public static function normalizeCode(?string $value): ?string
    {
        return IdentifierNormalizer::display($value);
    }

    /**
     * What makes two codes the same contract inside a development: trimmed,
     * inner whitespace collapsed, uppercased.
     *
     * The rule lives in {@see IdentifierNormalizer}, shared with
     * {@see ContractInstallment::normalizeNumberForComparison()}. Every place
     * that has to decide whether a code is already taken -- the form, the
     * contract import, the installment import's contract lookup and the unique
     * index -- resolves through here, so there is exactly one answer.
     */
    public static function normalizeCodeForComparison(?string $value): ?string
    {
        return IdentifierNormalizer::identity($value);
    }

    public function occupiesUnit(): bool
    {
        return $this->status instanceof ContractStatus && $this->status->occupiesUnit();
    }

    public function getFormattedSaleValueAttribute(): string
    {
        return 'R$ '.MoneyFormatter::formatCurrencyForDisplay($this->sale_value);
    }

    public static function resolveConstructionId(mixed $constructionUnitId): mixed
    {
        if (blank($constructionUnitId)) {
            return null;
        }

        return ConstructionUnit::query()->whereKey($constructionUnitId)->value('construction_id');
    }

    /**
     * Whether the distrato can already have happened on that date.
     *
     * A distrato is a fact, not a schedule. Recording one dated in the future
     * would put the contract in a state the two halves of the domain read
     * differently: {@see ContractStatus::occupiesUnit()} would consider the unit
     * free and let another contract be opened, while
     * {@see ContractOccupancyPeriod} would still see the unit held until that
     * date -- and the resale would be refused for overlapping something that has
     * not happened yet. Until the distrato takes effect the contract is ativo and
     * holds its unit.
     *
     * Registering a distrato today with effect later is a different feature, and
     * it needs a column of its own -- a date of record apart from a date of
     * effect -- not a future value in this one.
     */
    public static function cancellationDateHasTakenEffect(mixed $date): bool
    {
        $date = ValueComparator::date($date);

        return ($date !== null) && ($date <= now()->toDateString());
    }

    /**
     * How the unit has been held over time, for
     * {@see ContractOccupancyTimeline}. Every contract
     * of the unit, not only the one holding it now: an overlap is a question
     * about periods that have already closed.
     *
     * Soft deleted contracts are left out. A deleted contract keeps reserving its
     * code -- that is a rule about identity -- but it holds nothing.
     *
     * @return list<ContractOccupancyPeriod>
     */
    public static function occupancyPeriodsFor(mixed $constructionUnitId, mixed $ignoreId = null): array
    {
        if (blank($constructionUnitId)) {
            return [];
        }

        return self::query()
            ->with('clients:id,name')
            ->where('construction_unit_id', $constructionUnitId)
            ->when($ignoreId, fn (Builder $query): Builder => $query->whereKeyNot($ignoreId))
            ->get(['id', 'code', 'sale_date', 'cancellation_date', 'status'])
            ->map(fn (self $contract): ContractOccupancyPeriod => ContractOccupancyPeriod::fromContract($contract))
            ->all();
    }

    /**
     * Contract currently holding the unit, if any. This is what blocks a second
     * live contract and what a resale has to distratar first.
     */
    public static function occupyingContract(mixed $constructionUnitId, mixed $ignoreId = null): ?self
    {
        if (blank($constructionUnitId)) {
            return null;
        }

        return self::query()
            ->with('clients')
            ->where('construction_unit_id', $constructionUnitId)
            ->whereIn('status', ContractStatus::occupyingValues())
            ->when($ignoreId, fn (Builder $query): Builder => $query->whereKeyNot($ignoreId))
            ->first();
    }

    /**
     * The ids of everyone who bought under this contract.
     *
     * @return list<int>
     */
    public function buyerIds(): array
    {
        return $this->clients->map(fn (Client $client): int => (int) $client->getKey())->all();
    }

    /**
     * A compact reading of the buyers, for a table cell or a select option.
     *
     * Names only -- the document belongs to the client record, not to every
     * place the contract is mentioned.
     */
    public function buyersLabel(int $limit = 2): string
    {
        $names = $this->clients->pluck('name');

        if ($names->isEmpty()) {
            return 'Sem comprador';
        }

        $shown = $names->take($limit);
        $rest = $names->count() - $shown->count();

        return $shown->implode(', ').($rest > 0 ? " +{$rest}" : '');
    }

    /**
     * Replaces the buyer set and records what moved.
     *
     * The activity is written by hand rather than by `LogsActivity`, which only
     * watches columns: the buyers are rows of another table, so nothing would be
     * logged otherwise. It carries ids and never documents -- a name can be
     * resolved for display, a CPF in an audit trail cannot be taken back.
     *
     * Silent when the set holds, including when the file lists the same buyers
     * in a different order: a set has no order, so there is nothing to record.
     *
     * @param  list<int>  $clientIds
     * @return bool whether anything changed
     */
    public function syncBuyers(array $clientIds): bool
    {
        $before = $this->buyerIds();
        sort($before);

        $after = array_values(array_unique(array_map('intval', $clientIds)));
        sort($after);

        if ($before === $after) {
            return false;
        }

        $this->clients()->sync($after);
        $this->unsetRelation('clients');

        activity()
            ->performedOn($this)
            ->event('updated')
            ->withProperties([
                'old' => ['client_ids' => $before],
                'attributes' => ['client_ids' => $after],
            ])
            ->log('updated');

        return true;
    }

    /**
     * Whether the code is already taken inside the development. Scoped to the
     * development, not globally: different developments reuse numbering.
     *
     * Compares the normalized identity, so "A606", "a606" and " A606 " are one
     * code regardless of what the database collation would have said.
     *
     * Soft deleted contracts count, and that is deliberate: the code is part of
     * the historical identity of a commercial relationship, so a deleted A606
     * still owns A606 inside its development.
     */
    public static function isDuplicateCode(mixed $constructionId, ?string $code, mixed $ignoreId = null): bool
    {
        $code = self::normalizeCodeForComparison($code);

        if (blank($constructionId) || ($code === null)) {
            return false;
        }

        return self::withTrashed()
            ->where('construction_id', $constructionId)
            ->where('code_normalized', $code)
            ->when($ignoreId, fn (Builder $query): Builder => $query->whereKeyNot($ignoreId))
            ->exists();
    }

    /**
     * @param  Builder<Contract>  $query
     */
    public function scopeForEmission(Builder $query, mixed $emissionId): void
    {
        $query->whereHas(
            'construction',
            fn (Builder $constructionQuery): Builder => $constructionQuery->where('emission_id', $emissionId),
        );
    }

    /**
     * Free text search across the contract and everything reachable from it.
     *
     * @param  Builder<Contract>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $digits = Str::digitsOnly($term);

        $query->where(function (Builder $query) use ($term, $digits): void {
            $query->where('code', 'like', "%{$term}%")
                ->orWhereHas('clients', function (Builder $clientQuery) use ($term, $digits): void {
                    $clientQuery->where('name', 'like', "%{$term}%")
                        ->orWhere('trade_name', 'like', "%{$term}%");

                    if ($digits !== '') {
                        $clientQuery->orWhere('document', 'like', "%{$digits}%");
                    }
                })
                ->orWhereHas(
                    'constructionUnit',
                    fn (Builder $unitQuery): Builder => $unitQuery
                        ->where('unit', 'like', "%{$term}%")
                        ->orWhere('block', 'like', "%{$term}%"),
                )
                ->orWhereHas(
                    'construction',
                    fn (Builder $constructionQuery): Builder => $constructionQuery
                        ->where('development_name', 'like', "%{$term}%")
                        ->orWhereHas(
                            'emission',
                            fn (Builder $emissionQuery): Builder => $emissionQuery->where('name', 'like', "%{$term}%"),
                        ),
                );
        });
    }
}
