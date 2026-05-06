<?php

namespace App\Http\Requests\Nimbus;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class ListSubmissionIndexRequest extends FormRequest
{
    /**
     * @var array<int, string>
     */
    private const ALLOWED_STATUS_FILTERS = [
        'pending',
        'under_review',
        'completed',
        'rejected',
    ];

    /**
     * @var array<int, string>
     */
    private const ALLOWED_PERIOD_FILTERS = [
        '30',
        '90',
        '180',
        'all',
    ];

    public function authorize(): bool
    {
        return auth('nimbus')->check();
    }

    /**
     * @return array<string, string|array<string>>
     */
    public function rules(): array
    {
        return [
            'operation' => ['nullable', 'string', 'max:100'],
            'period' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'operation.string' => 'O filtro de operação informado é inválido.',
            'period.string' => 'O filtro de período informado é inválido.',
            'status.string' => 'O filtro de status informado é inválido.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $operation = $this->query('operation');
        $period = $this->query('period');
        $status = $this->query('status');

        $this->merge([
            'operation' => is_string($operation) ? Str::of($operation)->trim()->upper()->value() : null,
            'period' => is_string($period) ? Str::of($period)->trim()->lower()->value() : null,
            'status' => is_string($status) ? Str::of($status)->trim()->lower()->value() : null,
        ]);
    }

    public function statusFilter(): ?string
    {
        $status = $this->validated('status');

        if (! is_string($status)) {
            return null;
        }

        return in_array($status, self::ALLOWED_STATUS_FILTERS, true) ? $status : null;
    }

    public function operationFilter(): ?string
    {
        $operation = $this->validated('operation');

        if (! is_string($operation) || $operation === '') {
            return null;
        }

        return $operation;
    }

    public function periodFilter(): string
    {
        $period = $this->validated('period');

        if (! is_string($period)) {
            return '90';
        }

        return in_array($period, self::ALLOWED_PERIOD_FILTERS, true) ? $period : '90';
    }
}
