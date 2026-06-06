<?php

declare(strict_types=1);

namespace VisibilityDetector\Core\Report;

use InvalidArgumentException;

final readonly class ReportSummary
{
    public function __construct(
        public string $overallStatus,
        public string $overallPriority,
        public string $message,
        public ?string $highestPriorityAffectedQuery = null,
        public array $topProbableCauses = [],
        public array $topRecommendedActions = [],
        public array $evidenceReferences = [],
    ) {
        if (!in_array($overallStatus, ['visible', 'not_visible', 'uncertain'], true)) {
            throw new InvalidArgumentException('overallStatus is invalid.');
        }

        if (!in_array($overallPriority, ['low', 'medium', 'high', 'critical'], true)) {
            throw new InvalidArgumentException('overallPriority is invalid.');
        }

        if (trim($message) === '') {
            throw new InvalidArgumentException('message must not be empty.');
        }
    }

    public static function fromArray(array $data): self
    {
        return new self(
            overallStatus: self::requiredString($data, 'overallStatus'),
            overallPriority: self::requiredString($data, 'overallPriority'),
            message: self::requiredString($data, 'message'),
            highestPriorityAffectedQuery: self::optionalString($data, 'highestPriorityAffectedQuery'),
            topProbableCauses: $data['topProbableCauses'] ?? [],
            topRecommendedActions: $data['topRecommendedActions'] ?? [],
            evidenceReferences: $data['evidenceReferences'] ?? [],
        );
    }

    public function toArray(): array
    {
        return [
            'overallStatus' => $this->overallStatus,
            'overallPriority' => $this->overallPriority,
            'message' => $this->message,
            'highestPriorityAffectedQuery' => $this->highestPriorityAffectedQuery,
            'topProbableCauses' => $this->topProbableCauses,
            'topRecommendedActions' => $this->topRecommendedActions,
            'evidenceReferences' => $this->evidenceReferences,
        ];
    }

    private static function requiredString(array $data, string $field): string
    {
        if (!array_key_exists($field, $data) || !is_string($data[$field]) || trim($data[$field]) === '') {
            throw new InvalidArgumentException($field . ' is required.');
        }

        return $data[$field];
    }

    private static function optionalString(array $data, string $field): ?string
    {
        if (!array_key_exists($field, $data) || $data[$field] === null) {
            return null;
        }

        if (!is_string($data[$field])) {
            throw new InvalidArgumentException($field . ' must be a string.');
        }

        return $data[$field];
    }
}
