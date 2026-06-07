<?php

declare(strict_types=1);

namespace VisibilityDetector\Core\Detector;

use VisibilityDetector\Core\Page\ParsedPage;
use VisibilityDetector\Core\Report\Finding;

final readonly class ContentAlignmentDetector implements Detector
{
    private const DESCRIPTION_MIN_VISIBLE_CHARACTERS = 50;

    /**
     * @return array<int, Finding>
     */
    public function detect(DetectionContext $context): array
    {
        if (!$context->parsedPage instanceof ParsedPage) {
            return [];
        }

        $findings = [];
        $parsedPage = $context->parsedPage;
        $expectedTerms = $this->expectedTerms($context);

        if ($expectedTerms !== []) {
            $findings = array_merge($findings, $this->termFinding(
                code: 'content.title_missing_product_terms',
                message: 'The page title does not include any caller-supplied expected product terms.',
                recommendation: 'Include at least one relevant expected product term in the page title when it accurately describes the product.',
                expectedTerms: $expectedTerms,
                fieldName: 'title',
                fieldValue: $parsedPage->title,
            ));

            $findings = array_merge($findings, $this->termFinding(
                code: 'content.h1_missing_product_terms',
                message: 'The page H1 does not include any caller-supplied expected product terms.',
                recommendation: 'Include at least one relevant expected product term in the primary H1 when it accurately describes the product.',
                expectedTerms: $expectedTerms,
                fieldName: 'h1',
                fieldValue: $parsedPage->h1,
            ));
        }

        if ($this->isBlank($parsedPage->metaDescription)) {
            $findings[] = new Finding(
                code: 'content.description_missing',
                severity: 'medium',
                confidence: 0.9,
                message: 'The parsed page is missing a meta description.',
                evidence: [
                    'metaDescription' => $parsedPage->metaDescription,
                ],
                recommendation: 'Add a concise meta description that describes the product and its important attributes.',
            );
        } else {
            $description = trim((string) $parsedPage->metaDescription);
            $visibleCharacterCount = $this->visibleLength($description);

            if ($visibleCharacterCount < self::DESCRIPTION_MIN_VISIBLE_CHARACTERS) {
                $findings[] = new Finding(
                    code: 'content.description_too_thin',
                    severity: 'low',
                    confidence: 0.85,
                    message: 'The parsed page meta description is shorter than the deterministic minimum length threshold.',
                    evidence: [
                        'metaDescription' => $parsedPage->metaDescription,
                        'visibleCharacterCount' => $visibleCharacterCount,
                        'minimumVisibleCharacters' => self::DESCRIPTION_MIN_VISIBLE_CHARACTERS,
                    ],
                    recommendation: 'Expand the meta description with useful product details while keeping it concise.',
                );
            }
        }

        if ($expectedTerms !== []) {
            $findings = array_merge($findings, $this->termFinding(
                code: 'content.body_missing_product_terms',
                message: 'The body text summary does not include any caller-supplied expected product terms.',
                recommendation: 'Include at least one relevant expected product term in crawlable body copy when it accurately describes the product.',
                expectedTerms: $expectedTerms,
                fieldName: 'bodyTextSummary',
                fieldValue: $parsedPage->bodyTextSummary,
                excerpt: true,
            ));
        }

        return $findings;
    }

    /**
     * @return array<int, string>
     */
    private function expectedTerms(DetectionContext $context): array
    {
        $terms = [];

        foreach ($context->product->expectedTerms as $term) {
            $trimmed = trim($term);

            if ($trimmed !== '') {
                $terms[] = $trimmed;
            }
        }

        return array_values(array_unique($terms));
    }

    /**
     * @param array<int, string> $expectedTerms
     * @return array<int, Finding>
     */
    private function termFinding(
        string $code,
        string $message,
        string $recommendation,
        array $expectedTerms,
        string $fieldName,
        ?string $fieldValue,
        bool $excerpt = false,
    ): array {
        $matchedTerms = $this->matchedTerms($expectedTerms, $fieldValue);

        if ($matchedTerms !== []) {
            return [];
        }

        $evidence = [
            'expectedTerms' => $expectedTerms,
            'matchedTerms' => $matchedTerms,
            'missingTerms' => $expectedTerms,
            $fieldName => $excerpt ? $this->excerpt($fieldValue) : $fieldValue,
        ];

        return [new Finding(
            code: $code,
            severity: 'medium',
            confidence: 0.9,
            message: $message,
            evidence: $evidence,
            recommendation: $recommendation,
        )];
    }

    /**
     * @param array<int, string> $expectedTerms
     * @return array<int, string>
     */
    private function matchedTerms(array $expectedTerms, ?string $value): array
    {
        if ($this->isBlank($value)) {
            return [];
        }

        $haystack = $this->lower((string) $value);
        $matchedTerms = [];

        foreach ($expectedTerms as $term) {
            if (str_contains($haystack, $this->lower($term))) {
                $matchedTerms[] = $term;
            }
        }

        return $matchedTerms;
    }

    private function lower(string $value): string
    {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }

        return strtolower($value);
    }

    private function visibleLength(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }

        return strlen($value);
    }

    private function excerpt(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        if (strlen($trimmed) <= 160) {
            return $trimmed;
        }

        return substr($trimmed, 0, 157) . '...';
    }

    private function isBlank(?string $value): bool
    {
        return $value === null || trim($value) === '';
    }
}
