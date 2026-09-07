<?php

namespace App\Alerts;

use App\Enums\OperationalAlertSeverity;
use App\Enums\OperationalAlertType;

/**
 * A condition the evaluator observed to be true right now, expressed as everything the projector
 * needs to open or refresh an alert. All text is server-generated and bounded; nothing here is
 * copied from a request.
 */
final readonly class AlertCondition
{
    public function __construct(
        public OperationalAlertType $type,
        public string $subjectType,
        public int $subjectId,
        public OperationalAlertSeverity $severity,
        public string $subjectLabel,
        public string $title,
        public string $message,
    ) {}

    public function activeKey(): string
    {
        return $this->type->value.':'.$this->subjectType.':'.$this->subjectId;
    }
}
