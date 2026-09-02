<?php

namespace App\Enums;

enum MeasurementHistorySourceType: string
{
    case WorkflowActivity = 'workflow_activity';
    case ModelActivity = 'model_activity';
    case TableFallback = 'table_fallback';
}
