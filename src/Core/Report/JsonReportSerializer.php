<?php

declare(strict_types=1);

namespace VisibilityDetector\Core\Report;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;

final readonly class JsonReportSerializer implements ReportSerializer
{
    private const ENCODE_OPTIONS = JSON_THROW_ON_ERROR
        | JSON_PRETTY_PRINT
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE;

    private DiagnosticSummaryProjector $diagnosticSummaryProjector;

    public function __construct(?DiagnosticSummaryProjector $diagnosticSummaryProjector = null)
    {
        $this->diagnosticSummaryProjector = $diagnosticSummaryProjector ?? new DiagnosticSummaryProjector();
    }

    /**
     * @throws JsonException
     */
    public function serialize(VisibilityReport $report, ?DateTimeImmutable $generatedAt = null): string
    {
        $payload = $report->toArray();
        $payload['diagnosticSummary'] = $this->diagnosticSummaryProjector->project($report);

        if ($generatedAt !== null) {
            $payload['generatedAt'] = $this->normalizeGeneratedAt($generatedAt)->format(DATE_ATOM);
        } elseif (!array_key_exists('generatedAt', $payload) || $payload['generatedAt'] === null) {
            $payload['generatedAt'] = $this->normalizeGeneratedAt(new DateTimeImmutable())->format(DATE_ATOM);
        }

        return json_encode($payload, self::ENCODE_OPTIONS);
    }

    private function normalizeGeneratedAt(DateTimeImmutable $generatedAt): DateTimeImmutable
    {
        return $generatedAt->setTimezone(new DateTimeZone('UTC'));
    }
}
