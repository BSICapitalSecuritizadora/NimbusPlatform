<?php

namespace App\Actions\PuCalculator;

use App\Domain\PuCalculator\Services\PuOperationalMonitorService;
use App\Mail\PuCurveHealthAlertMail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

class SendPuCurveHealthAlertsAction
{
    private const SIGNATURE_CACHE_KEY = 'pu_calculator_alert_signature';

    public function __construct(
        private readonly PuOperationalMonitorService $monitor,
    ) {}

    /**
     * Envia alerta por e-mail quando ha problemas criticos, respeitando cooldown.
     * Retorna true quando um alerta foi efetivamente enviado.
     */
    public function handle(): bool
    {
        $issues = $this->monitor->criticalSummary();

        if ($issues === []) {
            return false;
        }

        /** @var list<string> $recipients */
        $recipients = config('pu_calculator.alerts.recipients', []);

        if ($recipients === []) {
            return false;
        }

        $signature = $this->monitor->criticalSignature();

        if (Cache::get(self::SIGNATURE_CACHE_KEY) === $signature) {
            return false;
        }

        Mail::to($recipients)->send(new PuCurveHealthAlertMail(
            issues: $issues,
            queueMetrics: $this->monitor->queueMetrics(),
            statusCounts: $this->monitor->statusCounts(),
        ));

        Cache::put(
            self::SIGNATURE_CACHE_KEY,
            $signature,
            now()->addMinutes((int) config('pu_calculator.alerts.cooldown_minutes', 180)),
        );

        return true;
    }
}
