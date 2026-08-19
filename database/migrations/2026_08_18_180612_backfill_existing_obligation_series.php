<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('obligations')
            ->leftJoin('extracted_obligations', 'extracted_obligations.id', '=', 'obligations.extracted_obligation_id')
            ->whereNull('obligations.obligation_series_id')
            ->whereNotNull('obligations.recurrence')
            ->where('obligations.recurrence', '<>', '')
            ->whereNotIn('obligations.recurrence', ['Única', 'Unica', 'Único', 'Unico'])
            ->select(['obligations.*', 'extracted_obligations.document_id as source_document_id'])
            ->orderBy('obligations.id')
            ->chunkById(100, function ($obligations): void {
                foreach ($obligations as $obligation) {
                    $frequency = match (mb_strtolower(trim((string) $obligation->recurrence))) {
                        'mensal' => 'monthly',
                        'trimestral' => 'quarterly',
                        'semestral' => 'semiannual',
                        'anual' => 'annual',
                        'sob demanda' => 'on_demand',
                        default => null,
                    };

                    $seriesId = DB::table('obligation_series')->insertGetId([
                        'emission_id' => $obligation->emission_id,
                        'extracted_obligation_id' => $obligation->extracted_obligation_id,
                        'document_id' => $obligation->source_document_id,
                        'responsible_user_id' => $obligation->responsible_user_id,
                        'title' => $obligation->title,
                        'obligation_type' => $obligation->obligation_type,
                        'obligation_category' => $obligation->obligation_category,
                        'description' => $obligation->description,
                        'responsible_party' => $obligation->responsible_party,
                        'responsible_area' => $obligation->responsible_area,
                        'priority' => $obligation->priority,
                        'required_evidence' => $obligation->required_evidence,
                        'due_rule' => $obligation->due_rule,
                        'source_clause' => $obligation->source_clause,
                        'source_page' => $obligation->source_page,
                        'source_excerpt' => $obligation->source_excerpt,
                        'frequency' => $frequency,
                        'generation_horizon_days' => 90,
                        'status' => 'awaiting_configuration',
                        'is_legacy_backfill' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    DB::table('obligations')
                        ->where('id', $obligation->id)
                        ->update([
                            'obligation_series_id' => $seriesId,
                            'generation_source' => 'legacy',
                        ]);
                }
            }, 'obligations.id', 'id');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $backfilledSeriesIds = DB::table('obligation_series')
            ->where('is_legacy_backfill', true)
            ->pluck('id');

        DB::table('obligations')
            ->whereIn('obligation_series_id', $backfilledSeriesIds)
            ->update([
                'obligation_series_id' => null,
                'obligation_series_rule_id' => null,
                'competence_date' => null,
                'generation_source' => null,
                'generated_at' => null,
            ]);

        DB::table('obligation_series')
            ->whereIn('id', $backfilledSeriesIds)
            ->delete();
    }
};
