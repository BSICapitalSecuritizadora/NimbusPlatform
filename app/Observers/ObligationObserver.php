<?php

namespace App\Observers;

use App\Models\Obligation;
use App\Services\Obligations\ObligationHistoryRecorder;

class ObligationObserver
{
    public function __construct(
        protected ObligationHistoryRecorder $historyRecorder,
    ) {}

    public function created(Obligation $obligation): void
    {
        $this->historyRecorder->recordCreated($obligation);
    }

    public function updated(Obligation $obligation): void
    {
        $this->historyRecorder->recordUpdated($obligation);
    }
}
