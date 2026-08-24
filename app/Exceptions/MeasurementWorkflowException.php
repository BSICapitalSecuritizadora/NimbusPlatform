<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class MeasurementWorkflowException extends RuntimeException implements ShouldntReport
{
    /**
     * @param  array<string, mixed>  $workflowContext
     */
    public function __construct(string $message, private readonly array $workflowContext = [])
    {
        parent::__construct($message);
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->workflowContext;
    }
}
