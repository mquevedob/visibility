<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use VisibilityDetector\Core\Detector\DetectionContext;
use VisibilityDetector\Core\Detector\Detector;
use VisibilityDetector\Core\Detector\VisibilityResultDetector;
use VisibilityDetector\Core\Product\ProductSubject;
use VisibilityDetector\Core\Report\Finding;
use VisibilityDetector\Core\Search\SearchQuery;
use VisibilityDetector\Core\Search\SearchResult;
use VisibilityDetector\Core\Search\SearchResultSet;
use VisibilityDetector\Core\Url\UrlMatch;

final class VisibilityResultDetectorTest extends TestCase
{
    public function test_matched_url_produces_visible_query_visibility(): void
    {
        $matchedResult = new SearchResult(position: 2, url: 'https://merchant.test/products/widget');
        $context = $this->context(
            resultSet: $this->resultSet(results: [$matchedResult]),
            urlMatch: new UrlMatch(
                matched: true,
                matchType: 'exact',
                expectedUrl: 'https://merchant.test/products/widget',
                matchedUrl: 'https://merchant.test/products/widget',
                matchedPosition: 2,
                matchedResult: $matchedResult,
                evidence: ['matchReason' => 'exact_url'],
            ),
        );

        $visibility = (new VisibilityResultDetector())->queryVisibility($context);

        self::assertSame('visible', $visibility->status);
        self::assertSame('product.visible_in_results', $visibility->findings[0]->code);
        self::assertSame($matchedResult, $visibility->matchedResult);
    }

    public function test_no_match_with_usable_result_set_produces_not_visible(): void
    {
        $context = $this->context(
            resultSet: $this->resultSet(results: [
                new SearchResult(position: 1, url: 'https://competitor.test/products/widget'),
            ]),
        );

        $visibility = (new VisibilityResultDetector())->queryVisibility($context);

        self::assertSame('not_visible', $visibility->status);
        self::assertSame('product.not_found_in_results', $visibility->findings[0]->code);
    }

    public function test_provider_warning_can_produce_uncertain(): void
    {
        $context = $this->context(
            resultSet: $this->resultSet(
                results: [new SearchResult(position: 1, url: 'https://competitor.test/products/widget')],
                warnings: ['provider returned a partial result set'],
            ),
        );

        $visibility = (new VisibilityResultDetector())->queryVisibility($context);

        self::assertSame('uncertain', $visibility->status);
        self::assertSame('product.visibility_uncertain', $visibility->findings[0]->code);
        self::assertSame(['provider returned a partial result set'], $visibility->warnings);
    }

    public function test_provider_limitation_can_produce_uncertain(): void
    {
        $context = $this->context(
            resultSet: $this->resultSet(
                results: [new SearchResult(position: 1, url: 'https://competitor.test/products/widget')],
                limitations: ['only the first result page was supplied'],
            ),
        );

        $visibility = (new VisibilityResultDetector())->queryVisibility($context);

        self::assertSame('uncertain', $visibility->status);
        self::assertSame('product.visibility_uncertain', $visibility->findings[0]->code);
        self::assertSame(['only the first result page was supplied'], $visibility->warnings);
    }

    public function test_expected_visible_true_plus_no_match_produces_expected_visibility_missing_finding(): void
    {
        $query = new SearchQuery(
            text: 'buy widget',
            provider: 'google',
            expectedVisibility: true,
            priority: 'high',
            reason: 'The query is an exact product-name query.',
        );
        $context = $this->context(
            query: $query,
            resultSet: $this->resultSet(
                query: $query,
                results: [new SearchResult(position: 1, url: 'https://competitor.test/products/widget')],
            ),
        );

        $findings = (new VisibilityResultDetector())->detect($context);

        self::assertSame('query.expected_visibility_missing', $findings[1]->code);
        self::assertTrue($findings[1]->evidence['expectedVisibility']);
        self::assertSame('The query is an exact product-name query.', $findings[1]->evidence['expectedVisibilityReason']);
        self::assertSame('high', $findings[1]->evidence['queryPriority']);
    }

    public function test_findings_include_required_fields(): void
    {
        $context = $this->context(
            resultSet: $this->resultSet(results: [
                new SearchResult(position: 1, url: 'https://competitor.test/products/widget'),
            ]),
        );

        $finding = (new VisibilityResultDetector())->detect($context)[0];
        $serialized = $finding->toArray();

        self::assertInstanceOf(Detector::class, new VisibilityResultDetector());
        self::assertInstanceOf(Finding::class, $finding);
        self::assertArrayHasKey('code', $serialized);
        self::assertArrayHasKey('severity', $serialized);
        self::assertArrayHasKey('confidence', $serialized);
        self::assertArrayHasKey('evidence', $serialized);
        self::assertArrayHasKey('recommendation', $serialized);
        self::assertIsArray($serialized['evidence']);
    }

    public function test_matched_result_is_preserved_in_query_visibility_when_visible(): void
    {
        $matchedResult = new SearchResult(
            position: 3,
            url: 'https://merchant.test/products/widget',
            title: 'Widget product page',
        );
        $context = $this->context(
            resultSet: $this->resultSet(results: [$matchedResult]),
            urlMatch: new UrlMatch(
                matched: true,
                matchType: 'exact',
                expectedUrl: 'https://merchant.test/products/widget',
                matchedUrl: 'https://merchant.test/products/widget',
                matchedPosition: 3,
                matchedResult: $matchedResult,
            ),
        );

        $visibility = (new VisibilityResultDetector())->queryVisibility($context);

        self::assertSame($matchedResult, $visibility->matchedResult);
        self::assertSame($matchedResult, $visibility->urlMatch->matchedResult);
    }

    public function test_url_match_evidence_is_preserved_in_findings(): void
    {
        $urlMatch = new UrlMatch(
            matched: false,
            matchType: 'none',
            expectedUrl: 'https://merchant.test/products/widget',
            evidence: [
                'resultCount' => 1,
                'checkedUrls' => ['https://competitor.test/products/widget'],
            ],
        );
        $context = $this->context(
            resultSet: $this->resultSet(results: [
                new SearchResult(position: 1, url: 'https://competitor.test/products/widget'),
            ]),
            urlMatch: $urlMatch,
        );

        $finding = (new VisibilityResultDetector())->detect($context)[0];

        self::assertSame($urlMatch->toArray(), $finding->evidence['urlMatch']);
        self::assertSame(['https://competitor.test/products/widget'], $finding->evidence['urlMatch']['evidence']['checkedUrls']);
    }

    private function context(
        ?SearchQuery $query = null,
        ?SearchResultSet $resultSet = null,
        ?UrlMatch $urlMatch = null,
    ): DetectionContext {
        $query ??= new SearchQuery(text: 'buy widget', provider: 'google');
        $resultSet ??= $this->resultSet(query: $query);
        $urlMatch ??= new UrlMatch(
            matched: false,
            matchType: 'none',
            expectedUrl: 'https://merchant.test/products/widget',
            evidence: ['resultCount' => count($resultSet->results)],
        );

        return new DetectionContext(
            product: new ProductSubject(expectedUrl: 'https://merchant.test/products/widget'),
            query: $query,
            resultSet: $resultSet,
            urlMatch: $urlMatch,
        );
    }

    /**
     * @param array<int, SearchResult> $results
     * @param array<int, string> $warnings
     * @param array<int, string> $limitations
     */
    private function resultSet(
        ?SearchQuery $query = null,
        array $results = [],
        array $warnings = [],
        array $limitations = [],
    ): SearchResultSet {
        return new SearchResultSet(
            query: $query ?? new SearchQuery(text: 'buy widget', provider: 'google'),
            results: $results,
            warnings: $warnings,
            limitations: $limitations,
        );
    }
}
