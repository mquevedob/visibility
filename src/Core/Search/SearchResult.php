<?php

declare(strict_types=1);

namespace VisibilityDetector\Core\Search;

use InvalidArgumentException;

final readonly class SearchResult
{
    public function __construct(
        public int $position,
        public string $url,
        public ?string $title = null,
        public ?string $snippet = null,
        public ?string $resultType = null,
        public array $providerPayload = [],
        public array $metadata = [],
    ) {
        if ($position < 1) {
            throw new InvalidArgumentException('position must be greater than or equal to 1.');
        }

        if (trim($url) === '') {
            throw new InvalidArgumentException('url must not be empty.');
        }
    }

    public static function fromArray(array $data): self
    {
        if (!isset($data['position']) || !is_int($data['position'])) {
            throw new InvalidArgumentException('position is required.');
        }

        if (!isset($data['url']) || !is_string($data['url'])) {
            throw new InvalidArgumentException('url is required.');
        }

        return new self(
            position: $data['position'],
            url: $data['url'],
            title: self::optionalString($data, 'title'),
            snippet: self::optionalString($data, 'snippet'),
            resultType: self::optionalString($data, 'resultType'),
            providerPayload: $data['providerPayload'] ?? [],
            metadata: $data['metadata'] ?? [],
        );
    }

    public function toArray(): array
    {
        return [
            'position' => $this->position,
            'url' => $this->url,
            'title' => $this->title,
            'snippet' => $this->snippet,
            'resultType' => $this->resultType,
            'providerPayload' => $this->providerPayload,
            'metadata' => $this->metadata,
        ];
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
