<?php

namespace App\Services;

use App\Enums\MeasurementReconciliationStatus;
use App\Exceptions\MeasurementWorkflowException;
use App\Models\Measurement;
use App\Models\MeasurementFinancialRule;
use App\Models\MeasurementPayment;
use App\Services\Security\ClamAvFileScanner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class MeasurementPaymentFinancialService
{
    public function __construct(
        private MeasurementFinancialReconciliationService $reconciliation,
        private MeasurementFinancialRuleService $rules,
        private DocumentStorageService $storage,
    ) {}

    /**
     * Called with the measurement locked inside the workflow transaction.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function assess(Measurement $measurement, array $data, ?MeasurementPayment $existing = null): array
    {
        Validator::make($data, [
            'financial_rule_id' => ['nullable', 'integer'],
            'financial_justification' => ['nullable', 'string', 'max:5000'],
        ])->validate();

        $measurement->setRelation('payments', $measurement->payments()->get()->reject(
            fn (MeasurementPayment $payment): bool => $payment->id === $existing?->id,
        ));
        $planSetId = (int) $data['plan_set_id'];
        $line = $this->reconciliation->forMeasurement($measurement, [$planSetId => $data['amount']])->line($planSetId);
        if ($line === null) {
            throw ValidationException::withMessages(['financial_rule_id' => 'Empreendimento fora da aprovação da Engenharia.']);
        }
        $exception = in_array($line->status, [MeasurementReconciliationStatus::Under, MeasurementReconciliationStatus::Over], true);
        $justification = trim((string) ($data['financial_justification'] ?? ''));
        Validator::make(['financial_justification' => $justification], [
            'financial_justification' => [$exception ? 'required' : 'nullable', 'string', 'max:5000'],
        ], ['financial_justification.required' => 'Explique a divergência financeira para a conferência do Finalizador.'])->validate();

        $rule = null;
        if (filled($data['financial_rule_id'] ?? null)) {
            $rule = $this->rules->availableFor($measurement, $planSetId, $data['pay_date'])
                ->whereKey($data['financial_rule_id'])->lockForUpdate()->first();
            if (! $rule || ! $this->covers($rule, $line->divergenceAmount, $line->expectedBalance)) {
                throw ValidationException::withMessages(['financial_rule_id' => 'A regra não cobre esta emissão, obra, data, direção ou valor. Escolha uma regra válida ou justifique como exceção sem regra.']);
            }
        }

        $support = $existing?->financial_assessment['support'] ?? null;
        $file = $data['financial_support'] ?? null;
        if ($file !== null) {
            Validator::make(['financial_support' => $file], [
                'financial_support' => ['file', 'mimes:pdf,jpg,jpeg,png', 'extensions:pdf,jpg,jpeg,png', 'max:10240'],
            ])->validate();
            $support = $this->storeSupport($file);
        }
        if ($rule?->requires_document && $support === null) {
            throw ValidationException::withMessages(['financial_support' => 'Esta regra exige documento de suporte (PDF, JPG ou PNG).']);
        }
        if ($support !== null) {
            $this->ensureSupportIntegrity($support);
        }

        return [
            'schema_version' => 1,
            'plan_set_id' => $planSetId,
            'pay_date' => $data['pay_date'],
            'amount' => $this->reconciliation->normalizeAmount($data['amount']),
            'engineering_hash' => $this->engineeringHash($measurement),
            'reference' => $line->toArray(),
            'requires_acceptance' => $exception,
            'justification' => $justification ?: null,
            'rule' => $rule?->snapshot(),
            'support' => $support,
        ];
    }

    private function covers(MeasurementFinancialRule $rule, ?string $difference, ?string $balance): bool
    {
        if ($difference === null || $balance === null || bccomp($difference, '0', 2) === 0) {
            return false;
        }
        $direction = bccomp($difference, '0', 2) < 0 ? 'under' : 'over';
        $absolute = ltrim($difference, '-');
        if (! in_array($rule->direction, ['both', $direction], true)
            || ($rule->maximum_difference_amount !== null && bccomp($absolute, $rule->maximum_difference_amount, 2) > 0)) {
            return false;
        }
        if ($rule->maximum_difference_percent !== null) {
            if (bccomp($balance, '0', 2) === 0) {
                return false;
            }

            return bccomp(bcmul($absolute, '100', 8), bcmul(ltrim($balance, '-'), $rule->maximum_difference_percent, 8), 8) <= 0;
        }

        return true;
    }

    public function requiresAcceptance(Measurement $measurement): bool
    {
        return $measurement->payments()->get()->contains(
            fn (MeasurementPayment $payment): bool => (bool) ($payment->financial_assessment['requires_acceptance'] ?? false),
        );
    }

    /** @return list<array<string, mixed>> */
    public function acceptForFinalization(Measurement $measurement, bool $accepted): array
    {
        $assessments = [];
        foreach ($measurement->payments()->lockForUpdate()->get() as $payment) {
            $assessment = $payment->financial_assessment;
            if ($assessment === null) {
                continue;
            }
            if (($assessment['engineering_hash'] ?? null) !== $this->engineeringHash($measurement)
                || (int) ($assessment['plan_set_id'] ?? 0) !== (int) $payment->plan_set_id
                || ($assessment['pay_date'] ?? null) !== $payment->pay_date->toDateString()
                || ($assessment['amount'] ?? null) !== $payment->amount) {
                throw new MeasurementWorkflowException('O contexto financeiro mudou. Devolva à etapa Pagamento para reavaliar o enquadramento.');
            }
            if ($assessment['support'] ?? null) {
                try {
                    $this->ensureSupportIntegrity($assessment['support']);
                } catch (ValidationException) {
                    throw new MeasurementWorkflowException('O documento de suporte do pagamento #'.$payment->id.' está ausente ou inválido. Devolva à etapa Pagamento para substituir o documento.');
                }
            }
            if ($assessment['requires_acceptance'] ?? false) {
                if (! $accepted) {
                    throw ValidationException::withMessages(['accept_financial_exceptions' => 'Confirme expressamente o aceite das divergências e justificativas financeiras.']);
                }
                $assessments[] = ['payment_id' => $payment->id, 'assessment' => $assessment];
            }
        }

        return $assessments;
    }

    private function engineeringHash(Measurement $measurement): string
    {
        return hash('sha256', json_encode($measurement->engineering_snapshot, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function storeSupport(UploadedFile $file): array
    {
        $scanner = app(ClamAvFileScanner::class);
        if ($scanner->isEnabled() && $scanner->scan($file->getRealPath() ?: null) !== ClamAvFileScanner::RESULT_CLEAN) {
            throw ValidationException::withMessages(['financial_support' => 'O documento não passou na verificação de segurança ou o antivírus está indisponível.']);
        }
        $stored = $this->storage->storePrivateFile($file, 'measurements/financial-support');
        foreach (app('db.transactions')->getPendingTransactions() as $transaction) {
            if ($transaction->connection === DB::connection()->getName()) {
                $transaction->addCallbackForRollback(fn () => Storage::disk($stored['disk'])->delete($stored['path']));
            }
        }
        try {
            app(MeasurementFileValidationService::class)->validateReceipt($stored['path'], $stored['disk']);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages([
                'financial_support' => collect($exception->errors())->flatten()->all(),
            ]);
        }
        $metadata = $this->storage->metadata($stored['path'], $stored['disk']);

        return [
            'disk' => $stored['disk'], 'path' => $stored['path'], 'sha256' => $this->storage->checksum($stored['path'], $stored['disk']),
            'name' => $file->getClientOriginalName(), 'mime_type' => $metadata['mime_type'], 'size' => $metadata['size_bytes'],
        ];
    }

    /** @param array<string, mixed> $support */
    public function ensureSupportIntegrity(array $support): void
    {
        $path = $support['path'] ?? '';
        $disk = $support['disk'] ?? '';
        if (! $this->storage->isAllowedMeasurementWriteDisk($disk) || ! $this->storage->isSafeStoredPath($path)) {
            throw ValidationException::withMessages(['financial_support' => 'Documento de suporte indisponível.']);
        }
        $hash = $this->storage->checksum($path, $disk);
        if (! is_string($hash) || ! is_string($support['sha256'] ?? null) || ! hash_equals($support['sha256'], $hash)) {
            throw ValidationException::withMessages(['financial_support' => 'Documento de suporte ausente ou com integridade inválida.']);
        }
    }
}
