<?php

namespace App\Actions\FundAlerts;

use App\Mail\FundBalanceBelowMinimumMail;
use App\Models\Fund;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendFundMinimumBalanceAlertAction
{
    public function handle(Fund $fund, ?CarbonInterface $checkedAt = null): bool
    {
        $checkedAt ??= now();

        $fund->loadMissing('emission.investors');

        if (! $fund->isBalanceBelowMinimum()) {
            $this->resetTrackingIfNeeded($fund);

            return false;
        }

        $recipientEmails = $fund->emission?->investors
            ?->filter(fn ($investor): bool => $investor->is_active && filled($investor->email))
            ->pluck('email')
            ->map(fn (string $email): string => mb_strtolower(trim($email)))
            ->unique()
            ->values();

        if (! ($recipientEmails instanceof Collection) || $recipientEmails->isEmpty()) {
            Log::info('Nenhum destinatario ativo encontrado para alerta de saldo minimo do fundo.', [
                'fund_id' => $fund->id,
                'emission_id' => $fund->emission_id,
            ]);

            return false;
        }

        $recipientHash = $this->recipientHash($recipientEmails);

        if (! $this->shouldSend($fund, $recipientHash)) {
            return false;
        }

        try {
            Mail::mailer((string) config('mail.default', 'log'))
                ->to($recipientEmails->all())
                ->send(new FundBalanceBelowMinimumMail($fund, $checkedAt));
        } catch (\Throwable $exception) {
            Log::warning('Falha ao enviar alerta de saldo minimo do fundo.', [
                'fund_id' => $fund->id,
                'emission_id' => $fund->emission_id,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }

        $fund->forceFill([
            'minimum_balance_alert_sent_at' => $checkedAt,
            'minimum_balance_alert_balance' => $fund->balance,
            'minimum_balance_alert_minimum_balance' => $fund->minimum_balance,
            'minimum_balance_alert_recipients_hash' => $recipientHash,
        ])->saveQuietly();

        return true;
    }

    protected function shouldSend(Fund $fund, string $recipientHash): bool
    {
        if ($fund->minimum_balance_alert_sent_at === null) {
            return true;
        }

        if ($this->normalizeDecimal($fund->minimum_balance_alert_balance) !== $this->normalizeDecimal($fund->balance)) {
            return true;
        }

        if ($this->normalizeDecimal($fund->minimum_balance_alert_minimum_balance) !== $this->normalizeDecimal($fund->minimum_balance)) {
            return true;
        }

        return $fund->minimum_balance_alert_recipients_hash !== $recipientHash;
    }

    protected function resetTrackingIfNeeded(Fund $fund): void
    {
        if (($fund->minimum_balance_alert_sent_at === null)
            && ($fund->minimum_balance_alert_balance === null)
            && ($fund->minimum_balance_alert_minimum_balance === null)
            && blank($fund->minimum_balance_alert_recipients_hash)) {
            return;
        }

        $fund->forceFill([
            'minimum_balance_alert_sent_at' => null,
            'minimum_balance_alert_balance' => null,
            'minimum_balance_alert_minimum_balance' => null,
            'minimum_balance_alert_recipients_hash' => null,
        ])->saveQuietly();
    }

    protected function recipientHash(Collection $recipientEmails): string
    {
        return hash('sha256', $recipientEmails->join('|'));
    }

    protected function normalizeDecimal(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }
}
