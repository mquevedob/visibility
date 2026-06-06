<?php

declare(strict_types=1);

namespace VisibilityDetector\Core\Search;

use InvalidArgumentException;

final readonly class SearchResultSet
{
    public function __construct(
        public SearchQuery $query,
        public array $results = [],
        public ?string $capturedAt = null,
        public array $warnings = [],
        public array $limitations = [],
    ) {
        foreach ($results as $result) {
            if (!$result instanceof SearchResult) {
                throw new InvalidArgumentException('results must contain only SearchResult objects.');
            }
        }
    }

    public static function fromArray(array $data): self
    {
        $query = $data['query'] ?? null;

        if (is_array($query)) {
            $query = SearchQuery::fromArray($query);
        }

        if (!$query instanceof SearchQuery) {
            throw new InvalidArgumentException('query is required.');
        }

        $results = array_map(
            static fn (mixed $result): SearchResult => is_array($result) ? SearchResult::fromArray($result) : $result,
            $data['results'] ?? [],
        );

        return new self(
            query: $query,
            results: $results,
            capturedAt: self::optionalString($data, 'capturedAt'),
            warnings: $data['warnings'] ?? [],
            limitations: $data['limitations'] ?? [],
        );
    }

    public function toArray(): array
    {
        return [
            'query' => $this->query->toArray(),
            'provider' => $this->query->provider,
            'locale' => $this->query->locale,
            'device' => $this->query->device,
            'capturedAt' => $this->capturedAt,
            'results' => array_map(
                static fn (SearchResult $result): array => $result->toArray(),
                $this->results,
            ),
            'warnings' => $this->warnings,
            'limitations' => $this->limitations,
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
