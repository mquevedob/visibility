<?php

declare(strict_types=1);

namespace VisibilityDetector\Core\Search;

use InvalidArgumentException;

final readonly class SearchQuery
{
    public function __construct(
        public string $text,
        public string $provider,
        public ?string $locale = null,
        public ?string $device = null,
        public ?string $intent = null,
        public ?bool $expectedVisibility = null,
        public ?string $priority = null,
        public ?string $reason = null,
    ) {
        self::requireNonEmpty($text, 'text');
        self::requireNonEmpty($provider, 'provider');
        self::validatePriority($priority, 'priority');
    }

    public static function fromArray(array $data): self
    {
        return new self(
            text: self::requiredString($data, 'text'),
            provider: self::requiredString($data, 'provider'),
            locale: self::optionalString($data, 'locale'),
            device: self::optionalString($data, 'device'),
            intent: self::optionalString($data, 'intent'),
            expectedVisibility: self::optionalBool($data, 'expectedVisibility'),
            priority: self::optionalString($data, 'priority'),
            reason: self::optionalString($data, 'reason'),
        );
    }

    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'provider' => $this->provider,
            'locale' => $this->locale,
            'device' => $this->device,
            'intent' => $this->intent,
            'expectedVisibility' => $this->expectedVisibility,
            'priority' => $this->priority,
            'reason' => $this->reason,
        ];
    }

    private static function requiredString(array $data, string $field): string
    {
        if (!array_key_exists($field, $data) || !is_string($data[$field])) {
            throw new InvalidArgumentException($field . ' is required.');
        }

        self::requireNonEmpty($data[$field], $field);

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

    private static function optionalBool(array $data, string $field): ?bool
    {
        if (!array_key_exists($field, $data) || $data[$field] === null) {
            return null;
        }

        if (!is_bool($data[$field])) {
            throw new InvalidArgumentException($field . ' must be a boolean.');
        }

        return $data[$field];
    }

    private static function requireNonEmpty(string $value, string $field): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException($field . ' must not be empty.');
        }
    }

    private static function validatePriority(?string $priority, string $field): void
    {
        if ($priority !== null && !in_array($priority, ['low', 'medium', 'high', 'critical'], true)) {
            throw new InvalidArgumentException($field . ' must be low, medium, high, or critical.');
        }
    }
}
