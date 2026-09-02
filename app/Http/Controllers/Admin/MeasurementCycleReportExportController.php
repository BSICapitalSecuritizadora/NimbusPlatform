<?php

namespace App\Http\Controllers\Admin;

use App\DTOs\Measurements\MeasurementCycleReportFilters;
use App\Enums\MeasurementHistoryCompleteness;
use App\Enums\MeasurementStageExitReason;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\MeasurementCycleReportExportService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MeasurementCycleReportExportController extends Controller
{
    public function __invoke(
        Request $request,
        MeasurementCycleReportExportService $export,
    ): BinaryFileResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $periodToRules = ['nullable', 'date_format:Y-m-d'];

        if ($request->filled('period_from')) {
            $periodToRules[] = 'after_or_equal:period_from';
        }

        $validated = $request->validate([
            'period_from' => ['nullable', 'date_format:Y-m-d'],
            'period_to' => $periodToRules,
            'operation_id' => ['nullable', 'integer', 'min:1'],
            'emission_id' => ['nullable', 'integer', 'min:1'],
            'measurement_id' => ['nullable', 'integer', 'min:1'],
            'stage' => ['nullable', 'integer', 'between:1,5'],
            'decision_type' => ['nullable', Rule::enum(MeasurementStageExitReason::class)],
            'actor_id' => ['nullable', 'integer', 'min:1'],
            'expected_responsible_id' => ['nullable', 'integer', 'min:1'],
            'completeness' => ['nullable', Rule::enum(MeasurementHistoryCompleteness::class)],
            'format' => ['nullable', Rule::in(['csv', 'xlsx'])],
        ]);

        return $export->download(
            $actor,
            MeasurementCycleReportFilters::fromArray($validated),
            (string) ($validated['format'] ?? 'xlsx'),
        );
    }
}
