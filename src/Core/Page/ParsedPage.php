<?php

declare(strict_types=1);

namespace VisibilityDetector\Core\Page;

use InvalidArgumentException;

final readonly class ParsedPage
{
    public function __construct(
        public string $url,
        public ?string $title = null,
        public ?string $metaDescription = null,
        public ?string $canonicalUrl = null,
        public array $robotsDirectives = [],
        public array $xRobotsDirectives = [],
        public array $hreflangLinks = [],
        public ?string $h1 = null,
        public array $headings = [],
        public array $links = [],
        public array $jsonLdBlocks = [],
        public array $schemaTypes = [],
        public array $productSchemaCandidates = [],
        public array $offerSchemaCandidates = [],
        public ?string $bodyTextSummary = null,
        public array $parserWarnings = [],
    ) {
        if (trim($url) === '') {
            throw new InvalidArgumentException('url must not be empty.');
        }
    }

    public static function fromArray(array $data): self
    {
        return new self(
            url: self::requiredString($data, 'url'),
            title: self::optionalString($data, 'title'),
            metaDescription: self::optionalString($data, 'metaDescription'),
            canonicalUrl: self::optionalString($data, 'canonicalUrl'),
            robotsDirectives: $data['robotsDirectives'] ?? [],
            xRobotsDirectives: $data['xRobotsDirectives'] ?? [],
            hreflangLinks: $data['hreflangLinks'] ?? [],
            h1: self::optionalString($data, 'h1'),
            headings: $data['headings'] ?? [],
            links: $data['links'] ?? [],
            jsonLdBlocks: $data['jsonLdBlocks'] ?? [],
            schemaTypes: $data['schemaTypes'] ?? [],
            productSchemaCandidates: $data['productSchemaCandidates'] ?? [],
            offerSchemaCandidates: $data['offerSchemaCandidates'] ?? [],
            bodyTextSummary: self::optionalString($data, 'bodyTextSummary'),
            parserWarnings: $data['parserWarnings'] ?? [],
        );
    }

    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'title' => $this->title,
            'metaDescription' => $this->metaDescription,
            'canonicalUrl' => $this->canonicalUrl,
            'robotsDirectives' => $this->robotsDirectives,
            'xRobotsDirectives' => $this->xRobotsDirectives,
            'hreflangLinks' => $this->hreflangLinks,
            'h1' => $this->h1,
            'headings' => $this->headings,
            'links' => $this->links,
            'jsonLdBlocks' => $this->jsonLdBlocks,
            'schemaTypes' => $this->schemaTypes,
            'productSchemaCandidates' => $this->productSchemaCandidates,
            'offerSchemaCandidates' => $this->offerSchemaCandidates,
            'bodyTextSummary' => $this->bodyTextSummary,
            'parserWarnings' => $this->parserWarnings,
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
