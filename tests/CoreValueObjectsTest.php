<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use VisibilityDetector\Core\Page\PageSnapshot;
use VisibilityDetector\Core\Page\ParsedPage;
use VisibilityDetector\Core\Product\ProductSubject;
use VisibilityDetector\Core\Report\Finding;
use VisibilityDetector\Core\Report\QueryVisibility;
use VisibilityDetector\Core\Report\ReportSummary;
use VisibilityDetector\Core\Report\VisibilityReport;
use VisibilityDetector\Core\Search\SearchQuery;
use VisibilityDetector\Core\Search\SearchResult;
use VisibilityDetector\Core\Search\SearchResultSet;
use VisibilityDetector\Core\Url\UrlMatch;

final class CoreValueObjectsTest extends TestCase
{
    public function test_product_subject_constructs_with_defaults(): void
    {
        $product = new ProductSubject(expectedUrl: 'https://merchant.test/products/widget');

        self::assertSame('https://merchant.test/products/widget', $product->expectedUrl);
        self::assertNull($product->name);
        self::assertSame([], $product->acceptableUrlVariants);
        self::assertSame([], $product->expectedTerms);
        self::assertSame([
            'expectedUrl' => 'https://merchant.test/products/widget',
            'id' => null,
            'name' => null,
            'brand' => null,
            'sku' => null,
            'category' => null,
            'acceptableUrlVariants' => [],
            'expectedTerms' => [],
            'commercialPriority' => null,
            'commercialValue' => null,
            'price' => null,
            'currency' => null,
            'stockStatus' => null,
        ], $product->toArray());
    }

    public function test_product_subject_constructs_from_array_with_context(): void
    {
        $product = ProductSubject::fromArray([
            'expectedUrl' => 'https://merchant.test/products/widget',
            'id' => 'p-1',
            'name' => 'Widget',
            'brand' => 'Acme',
            'sku' => 'W-1',
            'category' => 'Tools',
            'acceptableUrlVariants' => ['https://merchant.test/widget'],
            'expectedTerms' => ['widget', 'acme'],
            'commercialPriority' => 'high',
            'commercialValue' => ['margin' => 'caller supplied'],
            'price' => 19.95,
            'currency' => 'USD',
            'stockStatus' => 'in_stock',
        ]);

        self::assertSame('Widget', $product->name);
        self::assertSame(['https://merchant.test/widget'], $product->acceptableUrlVariants);
        self::assertSame('high', $product->commercialPriority);
        self::assertSame(19.95, $product->price);
    }

    public function test_product_subject_rejects_missing_expected_url(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ProductSubject::fromArray(['name' => 'Widget']);
    }

    public function test_product_subject_rejects_invalid_priority(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ProductSubject(
            expectedUrl: 'https://merchant.test/products/widget',
            commercialPriority: 'automatic',
        );
    }

    public function test_search_query_constructs_with_defaults(): void
    {
        $query = new SearchQuery(text: 'buy widget', provider: 'google');

        self::assertSame('buy widget', $query->text);
        self::assertSame('google', $query->provider);
        self::assertNull($query->expectedVisibility);
        self::assertSame([
            'text' => 'buy widget',
            'provider' => 'google',
            'locale' => null,
            'device' => null,
            'intent' => null,
            'expectedVisibility' => null,
            'priority' => null,
            'reason' => null,
        ], $query->toArray());
    }

    public function test_search_query_constructs_from_array_with_context(): void
    {
        $query = SearchQuery::fromArray([
            'text' => 'buy widget',
            'provider' => 'google',
            'locale' => 'en_US',
            'device' => 'mobile',
            'intent' => 'exact_product',
            'expectedVisibility' => true,
            'priority' => 'critical',
            'reason' => 'Caller expects exact product query to rank.',
        ]);

        self::assertSame('exact_product', $query->intent);
        self::assertTrue($query->expectedVisibility);
        self::assertSame('critical', $query->priority);
    }

    public function test_search_query_rejects_missing_text(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SearchQuery::fromArray(['provider' => 'google']);
    }

    public function test_search_query_rejects_missing_provider(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SearchQuery::fromArray(['text' => 'buy widget']);
    }

    public function test_search_result_set_serializes_multiple_results(): void
    {
        $resultSet = new SearchResultSet(
            query: new SearchQuery(
                text: 'buy widget',
                provider: 'google',
                locale: 'en_US',
                device: 'desktop',
            ),
            results: [
                new SearchResult(position: 1, url: 'https://merchant.test/products/widget', title: 'Widget'),
                new SearchResult(position: 2, url: 'https://competitor.test/widget', title: 'Competitor Widget'),
            ],
            capturedAt: '2026-06-06T12:00:00+00:00',
            warnings: ['supplied fixture'],
            limitations: ['top 10 only'],
        );

        $array = $resultSet->toArray();

        self::assertSame('google', $array['provider']);
        self::assertSame('en_US', $array['locale']);
        self::assertCount(2, $array['results']);
        self::assertSame(2, $array['results'][1]['position']);
        self::assertSame(['supplied fixture'], $array['warnings']);
    }

    public function test_finding_accepts_confidence_boundaries(): void
    {
        $low = new Finding(
            code: 'product.not_found_in_results',
            severity: 'high',
            confidence: 0.0,
            message: 'Product was not found.',
        );

        $high = new Finding(
            code: 'product.visible_in_results',
            severity: 'info',
            confidence: 1.0,
            message: 'Product was found.',
        );

        self::assertSame(0.0, $low->confidence);
        self::assertSame(1.0, $high->confidence);
    }

    public function test_finding_rejects_confidence_below_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Finding(
            code: 'product.not_found_in_results',
            severity: 'high',
            confidence: -0.01,
            message: 'Product was not found.',
        );
    }

    public function test_finding_rejects_confidence_above_one(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Finding(
            code: 'product.not_found_in_results',
            severity: 'high',
            confidence: 1.01,
            message: 'Product was not found.',
        );
    }

    public function test_visibility_report_serializes_nested_objects(): void
    {
        $query = new SearchQuery(
            text: 'buy widget',
            provider: 'google',
            expectedVisibility: true,
            priority: 'high',
        );
        $matchedResult = new SearchResult(
            position: 3,
            url: 'https://merchant.test/products/widget',
            title: 'Widget',
        );
        $urlMatch = new UrlMatch(
            matched: true,
            matchType: 'exact',
            expectedUrl: 'https://merchant.test/products/widget',
            matchedUrl: 'https://merchant.test/products/widget',
            matchedPosition: 3,
            matchedResult: $matchedResult,
            evidence: ['source' => 'fixture'],
        );
        $finding = new Finding(
            code: 'product.visible_in_results',
            severity: 'info',
            confidence: 1.0,
            message: 'Product is visible.',
            evidence: ['position' => 3],
            recommendation: 'Monitor visibility.',
        );

        $report = new VisibilityReport(
            product: new ProductSubject(
                expectedUrl: 'https://merchant.test/products/widget',
                name: 'Widget',
                commercialPriority: 'high',
            ),
            queryVisibilities: [
                new QueryVisibility(
                    query: $query,
                    status: 'visible',
                    urlMatch: $urlMatch,
                    matchedResult: $matchedResult,
                    findings: [$finding],
                    warnings: ['search evidence supplied externally'],
                ),
            ],
            pageSnapshot: new PageSnapshot(
                requestedUrl: 'https://merchant.test/products/widget',
                finalUrl: 'https://merchant.test/products/widget',
                statusCode: 200,
                contentType: 'text/html',
            ),
            parsedPage: new ParsedPage(
                url: 'https://merchant.test/products/widget',
                title: 'Widget',
                canonicalUrl: 'https://merchant.test/products/widget',
            ),
            summaryFindings: [$finding],
            warnings: ['runtime validation is owner-managed after merge'],
            generatedAt: '2026-06-06T12:00:00+00:00',
            summary: new ReportSummary(
                overallStatus: 'visible',
                overallPriority: 'low',
                message: 'Product is visible for supplied query evidence.',
                highestPriorityAffectedQuery: 'buy widget',
                topProbableCauses: [],
                topRecommendedActions: ['Monitor visibility.'],
                evidenceReferences: ['product.visible_in_results'],
            ),
        );

        $array = $report->toArray();

        self::assertSame('Widget', $array['product']['name']);
        self::assertSame('visible', $array['queryVisibilities'][0]['status']);
        self::assertSame(3, $array['queryVisibilities'][0]['urlMatch']['matchedPosition']);
        self::assertSame(200, $array['pageSnapshot']['statusCode']);
        self::assertSame('Widget', $array['parsedPage']['title']);
        self::assertSame('visible', $array['summary']['overallStatus']);
        self::assertSame('2026-06-06T12:00:00+00:00', $array['generatedAt']);
    }
}
