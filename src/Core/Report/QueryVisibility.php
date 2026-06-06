<?php

declare(strict_types=1);

namespace VisibilityDetector\Core\Report;

use InvalidArgumentException;
use VisibilityDetector\Core\Search\SearchQuery;
use VisibilityDetector\Core\Search\SearchResult;
use VisibilityDetector\Core\Url\UrlMatch;

final readonly class QueryVisibility
{
    public function __construct(
        public SearchQuery $query,
        public string $status,
        public UrlMatch $urlMatch,
        public ?SearchResult $matchedResult = null,
        public array $findings = [],
        public array $warnings = [],
    ) {
        if (!in_array($status, ['visible', 'not_visible', 'uncertain'], true)) {
            throw new InvalidArgumentException('status is invalid.');
        }

        foreach ($findings as $finding) {
            if (!$finding instanceof Finding) {
                throw new InvalidArgumentException('findings must contain only Finding objects.');
            }
        }
    }

    public static function fromArray(array $data): self
    {
        $query = $data['query'] ?? null;
        $urlMatch = $data['urlMatch'] ?? null;
        $matchedResult = $data['matchedResult'] ?? null;

        if (is_array($query)) {
            $query = SearchQuery::fromArray($query);
        }

        if (is_array($urlMatch)) {
            $urlMatch = UrlMatch::fromArray($urlMatch);
        }

        if (is_array($matchedResult)) {
            $matchedResult = SearchResult::fromArray($matchedResult);
        }

        if (!$query instanceof SearchQuery) {
            throw new InvalidArgumentException('query is required.');
        }

        if (!$urlMatch instanceof UrlMatch) {
            throw new InvalidArgumentException('urlMatch is required.');
        }

        return new self(
            query: $query,
            status: self::requiredString($data, 'status'),
            urlMatch: $urlMatch,
            matchedResult: $matchedResult,
            findings: array_map(
                static fn (mixed $finding): Finding => is_array($finding) ? Finding::fromArray($finding) : $finding,
                $data['findings'] ?? [],
            ),
            warnings: $data['warnings'] ?? [],
        );
    }

    public function toArray(): array
    {
        return [
            'query' => $this->query->toArray(),
            'provider' => $this->query->provider,
            'locale' => $this->query->locale,
            'device' => $this->query->device,
            'status' => $this->status,
            'urlMatch' => $this->urlMatch->toArray(),
            'matchedResult' => $this->matchedResult?->toArray(),
            'findings' => array_map(
                static fn (Finding $finding): array => $finding->toArray(),
                $this->findings,
            ),
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
}
