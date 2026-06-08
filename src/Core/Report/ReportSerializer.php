<?php

declare(strict_types=1);

namespace VisibilityDetector\Core\Report;

use DateTimeImmutable;

interface ReportSerializer
{
    public function serialize(VisibilityReport $report, ?DateTimeImmutable $generatedAt = null): string;
}
