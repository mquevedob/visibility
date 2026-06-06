<?php

declare(strict_types=1);

namespace VisibilityDetector\Core\Report;

use InvalidArgumentException;

final readonly class Finding
{
    public function __construct(
        public string $code,
        public string $severity,
        public float $confidence,
        public string $message,
        public array $evidence = [],
        public ?string $recommendation = null,
    ) {
        if (trim($code) === '') {
            throw new InvalidArgumentException('code must not be empty.');
        }

        if (!in_array($severity, ['critical', 'high', 'medium', 'low', 'info'], true)) {
            throw new InvalidArgumentException('severity is invalid.');
        }

        if ($confidence < 0.0 || $confidence > 1.0) {
            throw new InvalidArgumentException('confidence must be between 0.0 and 1.0.');
        }

        if (trim($message) === '') {
            throw new InvalidArgumentException('message must not be empty.');
        }
    }

    public static function fromArray(array $data): self
    {
        return new self(
            code: self::requiredString($data, 'code'),
            severity: self::requiredString($data, 'severity'),
            confidence: self::requiredFloat($data, 'confidence'),
            message: self::requiredString($data, 'message'),
            evidence: $data['evidence'] ?? [],
            recommendation: self::optionalString($data, 'recommendation'),
        );
    }

    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'severity' => $this->severity,
            'confidence' => $this->confidence,
            'message' => $this->message,
            'evidence' => $this->evidence,
            'recommendation' => $this->recommendation,
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

    private static function requiredFloat(array $data, string $field): float
    {
        if (!array_key_exists($field, $data) || (!is_int($data[$field]) && !is_float($data[$field]))) {
            throw new InvalidArgumentException($field . ' is required.');
        }

        return (float) $data[$field];
    }
}
