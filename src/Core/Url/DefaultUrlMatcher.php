<?php

declare(strict_types=1);

namespace VisibilityDetector\Core\Url;

use VisibilityDetector\Core\Product\ProductSubject;
use VisibilityDetector\Core\Search\SearchResult;
use VisibilityDetector\Core\Search\SearchResultSet;

final readonly class DefaultUrlMatcher implements UrlMatcher
{
    public function __construct(
        private UrlNormalizer $normalizer = new UrlNormalizer(),
    ) {
    }

    public function match(ProductSubject $product, SearchResultSet $resultSet): UrlMatch
    {
        $expectedUrl = $product->expectedUrl;
        $normalizedExpectedUrl = $this->normalizer->normalize($expectedUrl);
        $normalizedAcceptableVariants = array_map(
            fn (string $variant): string => $this->normalizer->normalize($variant),
            $product->acceptableUrlVariants,
        );

        foreach ($resultSet->results as $result) {
            if ($expectedUrl === $result->url) {
                return $this->matched(
                    matchType: 'exact',
                    expectedUrl: $expectedUrl,
                    result: $result,
                    evidence: [
                        'reason' => 'Raw expected URL equals raw result URL.',
                        'expectedUrl' => $expectedUrl,
                        'matchedUrl' => $result->url,
                    ],
                );
            }

            $normalizedResultUrl = $this->normalizer->normalize($result->url);

            if ($normalizedExpectedUrl === $normalizedResultUrl) {
                return $this->matched(
                    matchType: 'normalized',
                    expectedUrl: $expectedUrl,
                    result: $result,
                    evidence: [
                        'reason' => 'Normalized expected URL equals normalized result URL.',
                        'expectedUrl' => $expectedUrl,
                        'matchedUrl' => $result->url,
                        'normalizedExpectedUrl' => $normalizedExpectedUrl,
                        'normalizedMatchedUrl' => $normalizedResultUrl,
                    ],
                );
            }

            foreach ($normalizedAcceptableVariants as $index => $normalizedVariant) {
                if ($normalizedVariant === $normalizedResultUrl) {
                    return $this->matched(
                        matchType: 'acceptable_variant',
                        expectedUrl: $expectedUrl,
                        result: $result,
                        evidence: [
                            'reason' => 'Normalized acceptable URL variant equals normalized result URL.',
                            'expectedUrl' => $expectedUrl,
                            'matchedUrl' => $result->url,
                            'acceptableUrlVariant' => $product->acceptableUrlVariants[$index],
                            'normalizedAcceptableUrlVariant' => $normalizedVariant,
                            'normalizedMatchedUrl' => $normalizedResultUrl,
                        ],
                    );
                }
            }
        }

        return new UrlMatch(
            matched: false,
            matchType: 'none',
            expectedUrl: $expectedUrl,
            evidence: [
                'reason' => 'No supplied search result URL matched the expected URL or acceptable URL variants.',
                'expectedUrl' => $expectedUrl,
                'normalizedExpectedUrl' => $normalizedExpectedUrl,
                'normalizedAcceptableUrlVariants' => $normalizedAcceptableVariants,
                'resultCount' => count($resultSet->results),
            ],
        );
    }

    /**
     * @param array<string, mixed> $evidence
     */
    private function matched(string $matchType, string $expectedUrl, SearchResult $result, array $evidence): UrlMatch
    {
        return new UrlMatch(
            matched: true,
            matchType: $matchType,
            expectedUrl: $expectedUrl,
            matchedUrl: $result->url,
            matchedPosition: $result->position,
            matchedResult: $result,
            evidence: $evidence,
        );
    }
}
