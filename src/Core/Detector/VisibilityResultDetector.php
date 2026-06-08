<?php

declare(strict_types=1);

namespace VisibilityDetector\Core\Detector;

use VisibilityDetector\Core\Report\Finding;
use VisibilityDetector\Core\Report\QueryVisibility;

final class VisibilityResultDetector implements Detector
{
    public function queryVisibility(DetectionContext $context): QueryVisibility
    {
        $findings = $this->detect($context);

        return new QueryVisibility(
            query: $context->query,
            status: $this->statusFor($context),
            urlMatch: $context->urlMatch,
            matchedResult: $context->urlMatch->matchedResult,
            findings: $findings,
            warnings: $this->warningsFor($context),
        );
    }

    /**
     * @return array<int, Finding>
     */
    public function detect(DetectionContext $context): array
    {
        $findings = [];

        if ($context->urlMatch->matched) {
            $findings[] = new Finding(
                code: 'product.visible_in_results',
                severity: 'info',
                confidence: 1.0,
                message: 'The expected product URL was found in the supplied search results.',
                evidence: $this->evidenceFor($context),
                recommendation: 'Maintain the product URL and continue monitoring this query.',
            );

            return $findings;
        }

        if ($this->hasIncompleteEvidence($context)) {
            $findings[] = new Finding(
                code: 'product.visibility_uncertain',
                severity: 'medium',
                confidence: 0.5,
                message: 'The supplied search evidence is incomplete, so query visibility is uncertain.',
                evidence: $this->evidenceFor($context),
                recommendation: 'Provide a complete result set without provider warnings or limitations before treating this query as not visible.',
            );
        } else {
            $findings[] = new Finding(
                code: 'product.not_found_in_results',
                severity: 'medium',
                confidence: 0.9,
                message: 'The expected product URL was not found in the supplied search results.',
                evidence: $this->evidenceFor($context),
                recommendation: 'Review ranking, indexing, and product-page signals for this query.',
            );
        }

        if ($context->query->expectedVisibility === true) {
            $findings[] = new Finding(
                code: 'query.expected_visibility_missing',
                severity: 'high',
                confidence: $this->hasIncompleteEvidence($context) ? 0.6 : 0.9,
                message: 'The caller expected this product to be visible for the query, but no matching URL was found.',
                evidence: $this->evidenceFor($context) + [
                    'expectedVisibility' => true,
                    'expectedVisibilityReason' => $context->query->reason,
                    'queryPriority' => $context->query->priority,
                ],
                recommendation: 'Investigate why the product is absent for this expected-visible query.',
            );
        }

        return $findings;
    }

    private function statusFor(DetectionContext $context): string
    {
        if ($context->urlMatch->matched) {
            return 'visible';
        }

        if ($this->hasIncompleteEvidence($context)) {
            return 'uncertain';
        }

        return 'not_visible';
    }

    private function hasIncompleteEvidence(DetectionContext $context): bool
    {
        return $context->resultSet->warnings !== []
            || $context->resultSet->limitations !== []
            || $context->resultSet->results === [];
    }

    /**
     * @return array<string, mixed>
     */
    private function evidenceFor(DetectionContext $context): array
    {
        return [
            'product' => [
                'expectedUrl' => $context->product->expectedUrl,
                'acceptableUrlVariants' => $context->product->acceptableUrlVariants,
            ],
            'query' => $context->query->toArray(),
            'resultSet' => [
                'provider' => $context->query->provider,
                'locale' => $context->query->locale,
                'device' => $context->query->device,
                'resultCount' => count($context->resultSet->results),
                'warnings' => $context->resultSet->warnings,
                'limitations' => $context->resultSet->limitations,
            ],
            'urlMatch' => $context->urlMatch->toArray(),
            'urlEvidence' => [
                'expectedUrl' => $context->product->expectedUrl,
                'acceptableUrlVariants' => $context->product->acceptableUrlVariants,
                'matchedUrl' => $context->urlMatch->matchedUrl,
                'requestedUrl' => $context->pageSnapshot?->requestedUrl,
                'finalUrl' => $context->pageSnapshot?->finalUrl,
                'canonicalUrl' => $context->parsedPage?->canonicalUrl,
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function warningsFor(DetectionContext $context): array
    {
        return array_values(array_merge(
            $context->resultSet->warnings,
            $context->resultSet->limitations,
        ));
    }
}
