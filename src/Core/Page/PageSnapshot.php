<?php

declare(strict_types=1);

namespace VisibilityDetector\Core\Page;

use InvalidArgumentException;

final readonly class PageSnapshot
{
    public function __construct(
        public string $requestedUrl,
        public ?string $finalUrl = null,
        public ?int $statusCode = null,
        public array $headers = [],
        public ?string $body = null,
        public ?string $contentType = null,
        public array $redirects = [],
        public ?int $durationMs = null,
        public string $failureType = 'none',
        public array $warnings = [],
    ) {
        if (trim($requestedUrl) === '') {
            throw new InvalidArgumentException('requestedUrl must not be empty.');
        }

        if ($statusCode !== null && ($statusCode < 100 || $statusCode > 599)) {
            throw new InvalidArgumentException('statusCode must be between 100 and 599.');
        }

        if ($durationMs !== null && $durationMs < 0) {
            throw new InvalidArgumentException('durationMs must be greater than or equal to 0.');
        }

        if (!in_array($failureType, ['none', 'dns_not_found', 'timeout', 'connection_refused', 'ssl_error', 'http_error', 'invalid_response', 'unknown'], true)) {
            throw new InvalidArgumentException('failureType is invalid.');
        }
    }

    public static function fromArray(array $data): self
    {
        return new self(
            requestedUrl: self::requiredString($data, 'requestedUrl'),
            finalUrl: self::optionalString($data, 'finalUrl'),
            statusCode: self::optionalInt($data, 'statusCode'),
            headers: $data['headers'] ?? [],
            body: self::optionalString($data, 'body'),
            contentType: self::optionalString($data, 'contentType'),
            redirects: $data['redirects'] ?? [],
            durationMs: self::optionalInt($data, 'durationMs'),
            failureType: self::optionalString($data, 'failureType') ?? 'none',
            warnings: $data['warnings'] ?? [],
        );
    }

    public function toArray(): array
    {
        return [
            'requestedUrl' => $this->requestedUrl,
            'finalUrl' => $this->finalUrl,
            'statusCode' => $this->statusCode,
            'headers' => $this->headers,
            'body' => $this->body,
            'contentType' => $this->contentType,
            'redirects' => $this->redirects,
            'durationMs' => $this->durationMs,
            'failureType' => $this->failureType,
            'warnings' => $this->warnings,
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
