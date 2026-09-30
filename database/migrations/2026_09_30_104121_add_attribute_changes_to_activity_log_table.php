<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The two keys `spatie/laravel-activitylog` v4 used for tracked changes.
     *
     * @var list<string>
     */
    private const CHANGE_KEYS = ['attributes', 'old'];

    /**
     * Moves the tracked changes of every activity to their own column.
     *
     * v4 wrote what a model event changed inside `properties`, next to whatever
     * the caller passed to `withProperties()`. v5 writes it to
     * `attribute_changes` and leaves `properties` for the caller alone. The
     * history on record has to follow, otherwise everything logged before the
     * upgrade would read as an event that changed nothing.
     *
     * `batch_uuid` stays. v5 stopped filling it, but an import run is tied to
     * what it changed through that column, so the application stamps it itself
     * now -- see `App\Support\ActivityLog\LogBatch`.
     *
     * Safe to run again after a failure: MySQL does not roll DDL back, so the
     * column is only added when it is missing, and a row already moved no
     * longer matches the selection.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('activity_log', 'attribute_changes')) {
            Schema::table('activity_log', function (Blueprint $table): void {
                $table->json('attribute_changes')->nullable()->after('causer_id');
            });
        }

        $this->pendingRows()
            ->select('id', 'properties')
            ->chunkById(500, function (Collection $rows): void {
                DB::transaction(function () use ($rows): void {
                    foreach ($rows as $row) {
                        $this->moveChangesOutOfProperties($row->id, $row->properties);
                    }
                });
            });

        $left = $this->pendingRows()->count();

        if ($left > 0) {
            throw new RuntimeException(
                "Migração do activity_log abortada: {$left} registro(s) ainda guardam alterações em properties."
            );
        }
    }

    /**
     * Puts the changes back where v4 reads them and drops the column.
     *
     * Nothing is lost on the way back, but it only makes sense together with
     * the package downgrade: v5 does not look inside `properties` for changes.
     */
    public function down(): void
    {
        DB::table('activity_log')
            ->select('id', 'properties', 'attribute_changes')
            ->whereNotNull('attribute_changes')
            ->chunkById(500, function (Collection $rows): void {
                DB::transaction(function () use ($rows): void {
                    foreach ($rows as $row) {
                        $this->moveChangesBackIntoProperties($row->id, $row->properties, $row->attribute_changes);
                    }
                });
            });

        Schema::table('activity_log', function (Blueprint $table): void {
            $table->dropColumn('attribute_changes');
        });
    }

    /**
     * Activities still holding their changes inside `properties`.
     */
    private function pendingRows(): Builder
    {
        return DB::table('activity_log')
            ->whereNull('attribute_changes')
            ->where(function (Builder $query): void {
                foreach (self::CHANGE_KEYS as $key) {
                    $query->orWhereNotNull("properties->{$key}");
                }
            });
    }

    /**
     * Decoded as objects rather than arrays so that whatever the caller stored
     * keeps its shape: an empty object stays `{}` and does not come back as a
     * list. `updated_at` is left alone -- the row is an audit entry, and moving
     * a value between columns is not an edit of it.
     */
    private function moveChangesOutOfProperties(int $id, string $properties): void
    {
        $properties = $this->decode($properties);

        if (! $properties instanceof stdClass) {
            return;
        }

        $changes = new stdClass;

        foreach (self::CHANGE_KEYS as $key) {
            if (property_exists($properties, $key)) {
                $changes->{$key} = $properties->{$key};

                unset($properties->{$key});
            }
        }

        DB::table('activity_log')->where('id', $id)->update([
            'attribute_changes' => $this->encode($changes),
            'properties' => $this->encode($properties),
        ]);
    }

    private function moveChangesBackIntoProperties(int $id, ?string $properties, string $changes): void
    {
        $changes = $this->decode($changes);

        // The package stores `[]` for an activity that tracked nothing.
        if (! $changes instanceof stdClass) {
            return;
        }

        $properties = $this->decode($properties ?? '[]');

        if ($properties === []) {
            $properties = new stdClass;
        }

        if (! $properties instanceof stdClass) {
            return;
        }

        foreach (self::CHANGE_KEYS as $key) {
            if (property_exists($changes, $key)) {
                $properties->{$key} = $changes->{$key};
            }
        }

        DB::table('activity_log')->where('id', $id)->update([
            'properties' => $this->encode($properties),
        ]);
    }

    private function decode(string $json): mixed
    {
        return json_decode($json, false, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * An object left with no keys is written as `[]`, which is what the package
     * itself stores for an activity with no properties.
     */
    private function encode(stdClass $value): string
    {
        if (get_object_vars($value) === []) {
            return '[]';
        }

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }
};
