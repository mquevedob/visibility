<?php

declare(strict_types=1);

namespace VisibilityDetector\Core\Detector;

use VisibilityDetector\Core\Page\ParsedPage;
use VisibilityDetector\Core\Report\Finding;

final readonly class MetadataDetector implements Detector
{
    /**
     * @return array<int, Finding>
     */
    public function detect(DetectionContext $context): array
    {
        if (!$context->parsedPage instanceof ParsedPage) {
            return [new Finding(
                code: 'page.metadata_uncertain',
                severity: 'medium',
                confidence: 0.4,
                message: 'No parsed-page evidence was supplied, so metadata diagnostics are uncertain.',
                evidence: $this->baseEvidence($context),
                recommendation: 'Supply ParsedPage evidence before evaluating page metadata.',
            )];
        }

        $findings = [];
        $parsedPage = $context->parsedPage;

        if ($this->isBlank($parsedPage->title)) {
            $findings[] = $this->finding($context, 'page.title_missing', 'high', 'The parsed page is missing a title.', 'Add a descriptive HTML title for the product page.');
        }

        if ($this->isBlank($parsedPage->metaDescription)) {
            $findings[] = $this->finding($context, 'page.meta_description_missing', 'medium', 'The parsed page is missing a meta description.', 'Add a concise meta description that describes the product.');
        }

        if ($this->isBlank($parsedPage->h1)) {
            $findings[] = $this->finding($context, 'page.h1_missing', 'medium', 'The parsed page is missing an H1 heading.', 'Add one visible H1 heading that identifies the product.');
        }

        if ($parsedPage->productSchemaCandidates === []) {
            $findings[] = $this->finding($context, 'page.product_schema_missing', 'medium', 'The parsed page does not include Product structured-data candidates.', 'Add valid schema.org Product structured data for the product page.');
        }

        if ($parsedPage->offerSchemaCandidates === []) {
            // Keep the legacy page.* offer finding for now: Phase 1 only suppresses
            // duplicate Product schema root causes, while Offer evidence remains distinct.
            $findings[] = $this->finding($context, 'page.offer_schema_missing', 'medium', 'The parsed page does not include Offer structured-data candidates.', 'Add valid schema.org Offer structured data for price and availability signals when applicable.');
        }

        $missingTerms = $this->missingExpectedTerms($context);

        if ($missingTerms !== []) {
            $findings[] = new Finding(
                code: 'page.expected_terms_missing',
                severity: 'medium',
                confidence: 0.9,
                message: 'One or more caller-supplied expected terms were not found in parsed page metadata or content evidence.',
                evidence: $this->parsedPageEvidence($context) + ['missingTerms' => $missingTerms],
                recommendation: 'Include caller-supplied expected product terms in crawlable page metadata, headings, or summary content when relevant.',
            );
        }

        return $findings;
    }

    private function finding(DetectionContext $context, string $code, string $severity, string $message, string $recommendation): Finding
    {
        return new Finding(
            code: $code,
            severity: $severity,
            confidence: 0.9,
            message: $message,
            evidence: $this->parsedPageEvidence($context),
            recommendation: $recommendation,
        );
    }

    /**
     * @return array<int, string>
     */
    private function missingExpectedTerms(DetectionContext $context): array
    {
        if ($context->product->expectedTerms === [] || !$context->parsedPage instanceof ParsedPage) {
            return [];
        }

        $haystack = strtolower(implode(' ', array_filter([
            $context->parsedPage->title,
            $context->parsedPage->metaDescription,
            $context->parsedPage->h1,
            $this->headingsText($context->parsedPage),
            $context->parsedPage->bodyTextSummary,
        ], static fn (?string $value): bool => $value !== null && trim($value) !== '')));

        $missing = [];

        foreach ($context->product->expectedTerms as $term) {
            if (!str_contains($haystack, strtolower($term))) {
                $missing[] = $term;
            }
        }

        return $missing;
    }

    private function headingsText(ParsedPage $parsedPage): string
    {
        $texts = [];

        foreach ($parsedPage->headings as $heading) {
            if (is_array($heading) && isset($heading['text']) && is_string($heading['text'])) {
                $texts[] = $heading['text'];
            } elseif (is_string($heading)) {
                $texts[] = $heading;
            }
        }

        return implode(' ', $texts);
    }

    private function isBlank(?string $value): bool
    {
        return $value === null || trim($value) === '';
    }

    /**
     * @return array<string, mixed>
     */
    private function baseEvidence(DetectionContext $context): array
    {
        return [
            'product' => [
                'expectedUrl' => $context->product->expectedUrl,
                'expectedTerms' => $context->product->expectedTerms,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parsedPageEvidence(DetectionContext $context): array
    {
        $parsedPage = $context->parsedPage;

        return $this->baseEvidence($context) + [
            'parsedPage' => [
                'url' => $parsedPage?->url,
                'title' => $parsedPage?->title,
                'metaDescription' => $parsedPage?->metaDescription,
                'h1' => $parsedPage?->h1,
                'headingCount' => $parsedPage instanceof ParsedPage ? count($parsedPage->headings) : null,
                'productSchemaCandidateCount' => $parsedPage instanceof ParsedPage ? count($parsedPage->productSchemaCandidates) : null,
                'offerSchemaCandidateCount' => $parsedPage instanceof ParsedPage ? count($parsedPage->offerSchemaCandidates) : null,
                'bodyTextSummaryLength' => $parsedPage?->bodyTextSummary === null ? null : strlen($parsedPage->bodyTextSummary),
                'parserWarnings' => $parsedPage?->parserWarnings,
            ],
        ];
    }
}
