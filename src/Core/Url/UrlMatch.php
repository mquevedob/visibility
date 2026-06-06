<?php

declare(strict_types=1);

namespace VisibilityDetector\Core\Url;

use InvalidArgumentException;
use VisibilityDetector\Core\Search\SearchResult;

final readonly class UrlMatch
{
    public function __construct(
        public bool $matched,
        public string $matchType,
        public string $expectedUrl,
        public ?string $matchedUrl = null,
        public ?int $matchedPosition = null,
        public ?SearchResult $matchedResult = null,
        public array $evidence = [],
    ) {
        if (!in_array($matchType, ['none', 'exact', 'normalized', 'acceptable_variant', 'canonical'], true)) {
            throw new InvalidArgumentException('matchType is invalid.');
        }

        if (trim($expectedUrl) === '') {
            throw new InvalidArgumentException('expectedUrl must not be empty.');
        }

        if (!$matched && $matchType !== 'none') {
            throw new InvalidArgumentException('unmatched UrlMatch must use matchType none.');
        }
    }

    public static function fromArray(array $data): self
    {
        $matchedResult = $data['matchedResult'] ?? null;

        if (is_array($matchedResult)) {
            $matchedResult = SearchResult::fromArray($matchedResult);
        }

        return new self(
            matched: self::requiredBool($data, 'matched'),
            matchType: self::requiredString($data, 'matchType'),
            expectedUrl: self::requiredString($data, 'expectedUrl'),
            matchedUrl: self::optionalString($data, 'matchedUrl'),
            matchedPosition: self::optionalInt($data, 'matchedPosition'),
            matchedResult: $matchedResult,
            evidence: $data['evidence'] ?? [],
        );
    }

    public function toArray(): array
    {
        return [
            'matched' => $this->matched,
            'matchType' => $this->matchType,
            'expectedUrl' => $this->expectedUrl,
            'matchedUrl' => $this->matchedUrl,
            'matchedPosition' => $this->matchedPosition,
            'matchedResult' => $this->matchedResult?->toArray(),
            'evidence' => $this->evidence,
        ];
    }

    private static function requiredBool(array $data, string $field): bool
    {
        if (!array_key_exists($field, $data) || !is_bool($data[$field])) {
            throw new InvalidArgumentException($field . ' is required.');
        }

        return $data[$field];
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

    private static function optionalInt(array $data, string $field): ?int
    {
        if (!array_key_exists($field, $data) || $data[$field] === null) {
            return null;
        }

        if (!is_int($data[$field])) {
            throw new InvalidArgumentException($field . ' must be an integer.');
        }

        return $data[$field];
    }
}
