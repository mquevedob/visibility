<?php

declare(strict_types=1);

namespace VisibilityDetector\Core\Report;

use InvalidArgumentException;
use VisibilityDetector\Core\Page\PageSnapshot;
use VisibilityDetector\Core\Page\ParsedPage;
use VisibilityDetector\Core\Product\ProductSubject;

final readonly class VisibilityReport
{
    public function __construct(
        public ProductSubject $product,
        public array $queryVisibilities = [],
        public ?PageSnapshot $pageSnapshot = null,
        public ?ParsedPage $parsedPage = null,
        public array $summaryFindings = [],
        public array $warnings = [],
        public ?string $generatedAt = null,
        public ?ReportSummary $summary = null,
    ) {
        foreach ($queryVisibilities as $queryVisibility) {
            if (!$queryVisibility instanceof QueryVisibility) {
                throw new InvalidArgumentException('queryVisibilities must contain only QueryVisibility objects.');
            }
        }

        foreach ($summaryFindings as $finding) {
            if (!$finding instanceof Finding) {
                throw new InvalidArgumentException('summaryFindings must contain only Finding objects.');
            }
        }
    }

    public static function fromArray(array $data): self
    {
        $product = $data['product'] ?? null;
        $pageSnapshot = $data['pageSnapshot'] ?? null;
        $parsedPage = $data['parsedPage'] ?? null;
        $summary = $data['summary'] ?? null;

        if (is_array($product)) {
            $product = ProductSubject::fromArray($product);
        }

        if (is_array($pageSnapshot)) {
            $pageSnapshot = PageSnapshot::fromArray($pageSnapshot);
        }

        if (is_array($parsedPage)) {
            $parsedPage = ParsedPage::fromArray($parsedPage);
        }

        if (is_array($summary)) {
            $summary = ReportSummary::fromArray($summary);
        }

        if (!$product instanceof ProductSubject) {
            throw new InvalidArgumentException('product is required.');
        }

        return new self(
            product: $product,
            queryVisibilities: array_map(
                static fn (mixed $queryVisibility): QueryVisibility => is_array($queryVisibility) ? QueryVisibility::fromArray($queryVisibility) : $queryVisibility,
                $data['queryVisibilities'] ?? [],
            ),
            pageSnapshot: $pageSnapshot,
            parsedPage: $parsedPage,
            summaryFindings: array_map(
                static fn (mixed $finding): Finding => is_array($finding) ? Finding::fromArray($finding) : $finding,
                $data['summaryFindings'] ?? [],
            ),
            warnings: $data['warnings'] ?? [],
            generatedAt: self::optionalString($data, 'generatedAt'),
            summary: $summary,
        );
    }

    public function toArray(): array
    {
        return [
            'product' => $this->product->toArray(),
            'queryVisibilities' => array_map(
                static fn (QueryVisibility $queryVisibility): array => $queryVisibility->toArray(),
                $this->queryVisibilities,
            ),
            'urlEvidence' => $this->urlEvidence(),
            'pageSnapshot' => $this->pageSnapshot?->toArray(),
            'parsedPage' => $this->parsedPage?->toArray(),
            'summaryFindings' => array_map(
                static fn (Finding $finding): array => $finding->toArray(),
                $this->summaryFindings,
            ),
            'warnings' => $this->warnings,
            'generatedAt' => $this->generatedAt,
            'summary' => $this->summary?->toArray(),
        ];
    }


    /**
     * @return array<string, mixed>
     */
    private function urlEvidence(): array
    {
        $matchedUrls = [];

        foreach ($this->queryVisibilities as $queryVisibility) {
            if ($queryVisibility->urlMatch->matchedUrl !== null) {
                $matchedUrls[] = $queryVisibility->urlMatch->matchedUrl;
            }
        }

        return [
            'expectedUrl' => $this->product->expectedUrl,
            'acceptableUrlVariants' => $this->product->acceptableUrlVariants,
            'matchedUrls' => array_values(array_unique($matchedUrls)),
            'requestedUrl' => $this->pageSnapshot?->requestedUrl,
            'finalUrl' => $this->pageSnapshot?->finalUrl,
            'canonicalUrl' => $this->parsedPage?->canonicalUrl,
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
