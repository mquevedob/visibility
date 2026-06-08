<?php

declare(strict_types=1);

use VisibilityDetector\Adapters\Static\FixturePageFetcher;
use VisibilityDetector\Adapters\Static\StaticSearchProvider;
use VisibilityDetector\Core\Analyzer\VisibilityAnalyzer;
use VisibilityDetector\Core\Detector\VisibilityResultDetector;
use VisibilityDetector\Core\Page\DomPageParser;
use VisibilityDetector\Core\Page\PageSnapshot;
use VisibilityDetector\Core\Product\ProductSubject;
use VisibilityDetector\Core\Report\JsonReportSerializer;
use VisibilityDetector\Core\Search\SearchQuery;
use VisibilityDetector\Core\Search\SearchResultSet;
use VisibilityDetector\Core\Url\DefaultUrlMatcher;

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Run the deterministic demo analysis using one local search fixture.
 *
 * @param list<string> $acceptableUrlVariants
 */
function runExampleAnalysis(string $searchFixturePath, array $acceptableUrlVariants = []): void
{
    $product = new ProductSubject(
        expectedUrl: 'https://example.test/products/aurora-trail-shoe',
        id: 'demo-aurora-trail-shoe',
        name: 'Aurora Trail Shoe',
        brand: 'Acme Outdoor',
        category: 'Trail running shoes',
        acceptableUrlVariants: $acceptableUrlVariants,
        expectedTerms: ['Aurora Trail Shoe', 'Acme Outdoor', 'waterproof trail running shoes'],
        commercialPriority: 'critical',
        commercialValue: 'launch_product_high_margin',
        price: 149.00,
        currency: 'USD',
        stockStatus: 'in_stock',
    );

    $query = new SearchQuery(
        text: 'acme waterproof trail running shoes',
        provider: 'static-fixture',
        locale: 'en_US',
        device: 'desktop',
        intent: 'category_product',
        expectedVisibility: true,
        priority: 'critical',
        reason: 'High-value launch product should appear for branded category demand.',
    );

    $searchResultSet = SearchResultSet::fromArray(json_decode(
        file_get_contents($searchFixturePath),
        true,
        512,
        JSON_THROW_ON_ERROR,
    ));

    $productPageBody = file_get_contents(__DIR__ . '/fixtures/product-page.html');
    $productPageWithTrackingUrl = 'https://example.test/products/aurora-trail-shoe?utm_source=google&utm_campaign=test';
    $productPageAcceptableVariantUrl = 'https://example.test/p/aurora-trail-shoe';

    $productPageSnapshot = new PageSnapshot(
        requestedUrl: $product->expectedUrl,
        finalUrl: $product->expectedUrl,
        statusCode: 200,
        headers: ['content-type' => ['text/html; charset=utf-8']],
        body: $productPageBody,
        contentType: 'text/html; charset=utf-8',
        durationMs: 3,
        warnings: ['Fixture HTML intentionally includes noindex and a canonical URL pointing away from the product.'],
    );

    $productPageWithTrackingSnapshot = new PageSnapshot(
        requestedUrl: $productPageWithTrackingUrl,
        finalUrl: $product->expectedUrl,
        statusCode: 200,
        headers: ['content-type' => ['text/html; charset=utf-8']],
        body: $productPageBody,
        contentType: 'text/html; charset=utf-8',
        durationMs: 3,
        warnings: ['Fixture HTML intentionally includes noindex and a canonical URL pointing away from the product.'],
    );

    $productPageAcceptableVariantSnapshot = new PageSnapshot(
        requestedUrl: $productPageAcceptableVariantUrl,
        finalUrl: $product->expectedUrl,
        statusCode: 200,
        headers: ['content-type' => ['text/html; charset=utf-8']],
        body: $productPageBody,
        contentType: 'text/html; charset=utf-8',
        durationMs: 3,
        warnings: ['Fixture HTML intentionally includes noindex and a canonical URL pointing away from the product.'],
    );

    $analyzer = new VisibilityAnalyzer(
        searchProvider: new StaticSearchProvider([$searchResultSet]),
        urlMatcher: new DefaultUrlMatcher(),
        visibilityResultDetector: new VisibilityResultDetector(),
        pageFetcher: new FixturePageFetcher([
            $product->expectedUrl => $productPageSnapshot,
            $productPageWithTrackingUrl => $productPageWithTrackingSnapshot,
            $productPageAcceptableVariantUrl => $productPageAcceptableVariantSnapshot,
        ]),
        pageParser: new DomPageParser(),
    );

    $report = $analyzer->analyze($product, [$query]);

    echo (new JsonReportSerializer())->serialize(
        $report,
        new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
    ) . PHP_EOL;
}
