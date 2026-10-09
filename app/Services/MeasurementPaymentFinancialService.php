<?php

namespace App\Services;

use App\Concerns\ScansUploadedFile;
use App\DTOs\Measurements\MeasurementFinancialReconciliationLine;
use App\Enums\MeasurementReconciliationStatus;
use App\Exceptions\MeasurementWorkflowException;
use App\Models\Measurement;
use App\Models\MeasurementFinancialRule;
use App\Models\MeasurementPayment;
use App\Models\MeasurementReview;
use App\Services\Security\ClamAvFileScanner;
use App\Support\Uploads\LocalUploadedFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
     * Chamado dentro da transação do fluxo, sob o lock de quem chama: o
     * registro de pagamento já travou a Operation, a medição e os planos, nessa
     * ordem; a reavaliação travou a medição e o pagamento. Daqui em diante só
     * a regra financeira é travada -- depois deles, como manda a ordem
     * canônica de {@see MeasurementWorkflow}.
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

    /**
     * Se a Finalização exige o aceite expresso do Finalizador: há pagamento com
     * divergência justificada ou empreendimento exigido sem pagamento
     * ({@see self::unpaidRequiredPlanSets()}). É o que mostra e torna
     * obrigatória a confirmação no modal; a Finalização confere de novo.
     */
    public function requiresAcceptance(Measurement $measurement): bool
    {
        $payments = $measurement->payments()->get();

        return $payments->contains(
            fn (MeasurementPayment $payment): bool => (bool) ($payment->financial_assessment['requires_acceptance'] ?? false),
        ) || $this->unpaidRequiredPlanSetsAmong($measurement, $payments) !== []
            || ($measurement->isRevision() && app(MeasurementRevisionService::class)->unresolvedOverpayments($measurement) !== []);
    }

    /**
     * Empreendimentos que a Engenharia aprovou com valor esperado a pagar e que
     * não têm nenhum pagamento DESTA medição.
     *
     * A justificativa e o aceite de divergência ficam em cada pagamento, então
     * quem não recebeu nada não tinha onde guardá-los e passava pela etapa
     * Pagamento e pela Finalização sem justificativa nem aceite. O universo é
     * o `engineering_snapshot` -- conferido contra os arquivos e os planos na
     * Finalização --, e a conta é a da conciliação, sem fórmula própria.
     *
     * Não é exigido o empreendimento sem referência financeira (sem fundo de
     * obra no snapshot) nem o de valor esperado zero (0% realizado, fundo zero
     * ou valor arredondado a zero): como em {@see self::assess()}, nada ali
     * pede aceite. Quem recebeu parte do valor também não entra: a diferença é
     * divergência do próprio pagamento, já justificada e aceita por ele.
     *
     * Relê os pagamentos sem alterar a medição recebida -- a página passa o
     * próprio registro, com relações carregadas que ela continua usando.
     *
     * @return list<MeasurementFinancialReconciliationLine>
     */
    public function unpaidRequiredPlanSets(Measurement $measurement): array
    {
        return $this->unpaidRequiredPlanSetsAmong($measurement, $measurement->payments()->get());
    }

    /**
     * Os empreendimentos omitidos como a pessoa lê: nome e valor em aberto --
     * o esperado, ou, numa revisão, o esperado menos o que a família já pagou
     * antes dela (sem pagamento próprio, as duas contas coincidem na medição
     * sem revisão).
     *
     * @param  list<MeasurementFinancialReconciliationLine>  $lines
     */
    public function describeUnpaidPlanSets(array $lines): string
    {
        return collect($lines)
            ->map(fn (MeasurementFinancialReconciliationLine $line): string => sprintf(
                '%s (%s)',
                $line->label,
                MeasurementFinancialReconciliationService::formatCurrency($line->expectedBalance),
            ))
            ->implode(', ');
    }

    /**
     * Confere, na Finalização, a ausência de pagamento justificada na etapa
     * Pagamento e devolve o que foi aceito, para a auditoria congelar.
     *
     * Roda depois de {@see self::acceptForFinalization()} e das conferências de
     * integridade. A justificativa é a nota da aprovação da etapa Pagamento;
     * em branco -- aprovação anterior a esta regra -- a medição precisa voltar
     * àquela etapa, porque o Finalizador não aceita o que ninguém justificou.
     *
     * @return list<array{plan_set_id: int, reference: array<string, mixed>, justification: string, justified_by: int|null, justified_at: string|null}>
     */
    public function acceptUnpaidPlanSetsForFinalization(Measurement $measurement, bool $accepted): array
    {
        $unpaid = $this->unpaidRequiredPlanSets($measurement);

        if ($unpaid === []) {
            return [];
        }

        $paymentReview = $measurement->reviews()
            ->where('stage', MeasurementWorkflow::STAGE_PAYMENT)
            ->where('status', 'approved')
            ->first();
        $justification = trim((string) $paymentReview?->notes);

        if (! $paymentReview instanceof MeasurementReview || $justification === '') {
            throw new MeasurementWorkflowException(
                'Há empreendimento sem pagamento nesta competência e sem justificativa da etapa Pagamento: '.$this->describeUnpaidPlanSets($unpaid).'. Devolva à etapa Pagamento para registrar o pagamento ou justificar a ausência.',
                [
                    'measurement_id' => $measurement->getKey(),
                    'unpaid_plan_set_ids' => array_map(fn (MeasurementFinancialReconciliationLine $line): int => $line->planSetId, $unpaid),
                ],
            );
        }

        if (! $accepted) {
            throw ValidationException::withMessages([
                'accept_financial_exceptions' => 'Confirme expressamente o aceite da ausência de pagamento justificada na etapa Pagamento: '.$this->describeUnpaidPlanSets($unpaid).'.',
            ]);
        }

        return array_map(fn (MeasurementFinancialReconciliationLine $line): array => [
            'plan_set_id' => $line->planSetId,
            'reference' => $line->toArray(),
            'justification' => $justification,
            'justified_by' => $paymentReview->reviewer_user_id === null ? null : (int) $paymentReview->reviewer_user_id,
            'justified_at' => $paymentReview->reviewed_at?->toIso8601String(),
        ], $unpaid);
    }

    /**
     * Confere, na Finalização de uma revisão, o valor pago a maior que ela
     * deixou -- a família pagou, antes dela, mais do que ela aprova -- e devolve
     * o que foi aceito, para a auditoria congelar. Nada é estornado nem
     * compensado: os pagamentos registrados ficam como estão, e o aceite é o
     * reconhecimento expresso de que o valor não foi recuperado no sistema.
     *
     * A decisão financeira é a nota da aprovação da etapa Pagamento da própria
     * revisão; em branco, a revisão volta àquela etapa.
     *
     * @return list<array<string, mixed>>
     */
    public function acceptRevisionOverpaymentsForFinalization(Measurement $measurement, bool $accepted): array
    {
        if (! $measurement->isRevision()) {
            return [];
        }

        $revisions = app(MeasurementRevisionService::class);
        $overpayments = $revisions->unresolvedOverpayments($measurement);

        if ($overpayments === []) {
            return [];
        }

        $paymentReview = $measurement->reviews()
            ->where('stage', MeasurementWorkflow::STAGE_PAYMENT)
            ->where('status', 'approved')
            ->first();
        $decision = trim((string) $paymentReview?->notes);

        if (! $paymentReview instanceof MeasurementReview || $decision === '') {
            throw new MeasurementWorkflowException(
                'Esta revisão deixa valor pago a maior sem a decisão financeira da etapa Pagamento: '.$revisions->describeOverpayments($overpayments).'. Devolva à etapa Pagamento para registrar a decisão.',
                ['measurement_id' => $measurement->getKey()],
            );
        }

        if (! $accepted) {
            throw ValidationException::withMessages([
                'accept_financial_exceptions' => 'Confirme expressamente o aceite do valor pago a maior desta revisão, sem devolução registrada no sistema: '.$revisions->describeOverpayments($overpayments).'.',
            ]);
        }

        return array_map(fn ($position): array => [
            'plan_set_id' => $position->planSetId,
            'position' => $position->toArray(),
            'decision' => $decision,
            'decided_by' => $paymentReview->reviewer_user_id === null ? null : (int) $paymentReview->reviewer_user_id,
            'decided_at' => $paymentReview->reviewed_at?->toIso8601String(),
        ], $overpayments);
    }

    /**
     * @param  Collection<int, MeasurementPayment>  $payments
     * @return list<MeasurementFinancialReconciliationLine>
     */
    private function unpaidRequiredPlanSetsAmong(Measurement $measurement, Collection $payments): array
    {
        $paidPlanSetIds = $payments
            ->pluck('plan_set_id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->all();
        $current = $measurement->withoutRelations()->setRelation('payments', $payments);

        return array_values(array_filter(
            $this->reconciliation->forMeasurement($current)->lines,
            fn (MeasurementFinancialReconciliationLine $line): bool => $line->hasFinancialReference()
                && bccomp((string) $line->expectedBalance, '0', MeasurementFinancialReconciliationService::MONEY_SCALE) > 0
                && ! in_array($line->planSetId, $paidPlanSetIds, true),
        ));
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

    /**
     * Toda recusa do antivírus vai para o log como crítica, como em
     * {@see ScansUploadedFile::rejectUploadedFile()}: a falha da
     * varredura é engolida sem relatório, e o antivírus fora do ar bloqueia
     * todo pagamento com documento de suporte sem deixar rastro.
     *
     * @return array<string, mixed>
     */
    private function storeSupport(UploadedFile $file): array
    {
        $scanner = app(ClamAvFileScanner::class);
        /**
         * A varredura lê o arquivo por um caminho local -- uma cópia, quando o
         * envio temporário está num disco remoto. Arquivo ilegível nunca passa.
         */
        $scanResult = $scanner->isEnabled()
            ? rescue(
                fn (): string => LocalUploadedFile::using($file, fn (string $path): string => $scanner->scan($path)),
                ClamAvFileScanner::RESULT_UNAVAILABLE,
                report: false,
            )
            : ClamAvFileScanner::RESULT_CLEAN;
        if ($scanResult !== ClamAvFileScanner::RESULT_CLEAN) {
            Log::critical('Upload bloqueado pela varredura antivírus.', [
                'reason' => $scanResult === ClamAvFileScanner::RESULT_INFECTED ? 'malware_detectado' : 'antivirus_indisponivel',
                'field' => 'financial_support',
                'original_filename' => $file->getClientOriginalName(),
            ]);

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
