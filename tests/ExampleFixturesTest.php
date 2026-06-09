<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use VisibilityDetector\Adapters\Static\FixturePageFetcher;
use VisibilityDetector\Adapters\Static\StaticSearchProvider;
use VisibilityDetector\Core\Analyzer\VisibilityAnalyzer;
use VisibilityDetector\Core\Detector\VisibilityResultDetector;
use VisibilityDetector\Core\Page\DomPageParser;
use VisibilityDetector\Core\Page\PageSnapshot;
use VisibilityDetector\Core\Product\ProductSubject;
use VisibilityDetector\Core\Report\Finding;
use VisibilityDetector\Core\Search\SearchQuery;
use VisibilityDetector\Core\Search\SearchResult;
use VisibilityDetector\Core\Search\SearchResultSet;
use VisibilityDetector\Core\Url\DefaultUrlMatcher;

final class ExampleFixturesTest extends TestCase
{
    private const PRODUCT_URL = 'https://example.test/products/aurora-trail-shoe';

    private const KNOWN_SUMMARY_CATEGORIES = [
        'availability_blocker',
        'indexability_blocker',
        'canonical_blocker',
        'visibility_quality',
        'content_quality',
        'diagnostic',
    ];

    public function test_sample_report_is_valid_json_with_required_demo_sections(): void
    {
        $payload = $this->sampleReportPayload();

        self::assertSame('2026-01-01T00:00:00+00:00', $payload['generatedAt'] ?? null);
        self::assertArrayHasKey('product', $payload);
        self::assertArrayHasKey('queryVisibilities', $payload);
        self::assertArrayHasKey('summary', $payload);
        self::assertArrayHasKey('topProbableCauses', $payload['summary']);
        self::assertArrayHasKey('topRecommendedActions', $payload['summary']);
    }

    public function test_sample_report_demonstrates_local_fixture_only_visibility_gap(): void
    {
        $payload = $this->sampleReportPayload();

        self::assertSame('not_visible', $payload['summary']['overallStatus']);
        self::assertSame('critical', $payload['summary']['overallPriority']);
        self::assertSame(self::PRODUCT_URL, $payload['product']['expectedUrl']);
        self::assertTrue($payload['queryVisibilities'][0]['query']['expectedVisibility']);
        self::assertSame('static-fixture', $payload['queryVisibilities'][0]['provider']);
        self::assertSame('blocked', $payload['queryVisibilities'][0]['visibilityHealth']);
        self::assertContains('page.noindex_meta', array_column($payload['queryVisibilities'][0]['findings'], 'code'));
        self::assertContains('canonical.points_to_other_url', array_column($payload['queryVisibilities'][0]['findings'], 'code'));
    }

    public function test_sample_report_uses_current_summary_category_taxonomy(): void
    {
        $payload = $this->sampleReportPayload();
        $categories = $this->summaryCategories($payload);

        self::assertNotContains('structured_data_gap', $categories);

        foreach ($categories as $category) {
            self::assertContains($category, self::KNOWN_SUMMARY_CATEGORIES);
        }
    }

    public function test_schema_product_missing_uses_visibility_quality_in_summary(): void
    {
        $payload = $this->sampleReportPayload();
        $sawSchemaProductMissing = false;

        foreach ($this->summaryEntries($payload) as $entry) {
            if (($entry['code'] ?? null) !== 'schema.product_missing') {
                continue;
            }

            $sawSchemaProductMissing = true;
            self::assertSame('visibility_quality', $entry['category'] ?? null);
        }

        self::assertTrue($sawSchemaProductMissing);
    }

    public function test_example_runner_uses_static_fixtures_instead_of_external_services(): void
    {
        $runner = file_get_contents(__DIR__ . '/../examples/run-analysis.php');
        $basicScript = file_get_contents(__DIR__ . '/../examples/basic-analysis.php');

        self::assertIsString($runner);
        self::assertIsString($basicScript);
        self::assertStringContainsString('StaticSearchProvider', $runner);
        self::assertStringContainsString('FixturePageFetcher', $runner);
        self::assertStringContainsString('runExampleAnalysis', $basicScript);

        foreach ([$runner, $basicScript] as $script) {
            self::assertStringNotContainsString('curl_', $script);
            self::assertStringNotContainsString('HttpClient', $script);
            self::assertStringNotContainsString('https://www.google.', $script);
            self::assertStringNotContainsString('https://www.bing.', $script);
        }
    }

    public function test_focused_product_page_fixtures_and_original_noisy_fixture_exist(): void
    {
        foreach ([
            'product-page.html',
            'product-page-clean.html',
            'product-page-noindex.html',
            'product-page-canonical-mismatch.html',
            'product-page-missing-schema.html',
        ] as $fixture) {
            $contents = file_get_contents(__DIR__ . '/../examples/fixtures/' . $fixture);

            self::assertIsString($contents);
            self::assertStringContainsString('Aurora Trail Shoe', $contents);
            self::assertStringContainsString('Acme Outdoor', $contents);
        }
    }

    public function test_clean_fixture_does_not_emit_focused_technical_defects(): void
    {
        $codes = $this->focusedFixtureFindingCodes('product-page-clean.html');

        self::assertNotContains('page.noindex_meta', $codes);
        self::assertNotContains('canonical.points_to_other_url', $codes);
        self::assertNotContains('schema.product_missing', $codes);
    }

    public function test_noindex_fixture_emits_noindex_without_canonical_or_schema_defects(): void
    {
        $codes = $this->focusedFixtureFindingCodes('product-page-noindex.html');

        self::assertContains('page.noindex_meta', $codes);
        self::assertNotContains('canonical.points_to_other_url', $codes);
        self::assertNotContains('schema.product_missing', $codes);
    }

    public function test_canonical_mismatch_fixture_emits_canonical_mismatch_without_noindex_or_schema_defects(): void
    {
        $codes = $this->focusedFixtureFindingCodes('product-page-canonical-mismatch.html');

        self::assertContains('canonical.points_to_other_url', $codes);
        self::assertNotContains('page.noindex_meta', $codes);
        self::assertNotContains('schema.product_missing', $codes);
    }

    public function test_missing_schema_fixture_emits_product_schema_missing_without_noindex_or_canonical_defects(): void
    {
        $codes = $this->focusedFixtureFindingCodes('product-page-missing-schema.html');

        self::assertContains('schema.product_missing', $codes);
        self::assertNotContains('page.noindex_meta', $codes);
        self::assertNotContains('canonical.points_to_other_url', $codes);
    }

    /**
     * @return array<string, mixed>
     */
    private function sampleReportPayload(): array
    {
        $json = file_get_contents(__DIR__ . '/../examples/sample-report.json');

        self::assertIsString($json);
        self::assertJson($json);

        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($payload);

        return $payload;
    }

    /**
     * @return array<int, string>
     */
    private function focusedFixtureFindingCodes(string $fixture): array
    {
        $body = file_get_contents(__DIR__ . '/../examples/fixtures/' . $fixture);

        self::assertIsString($body);

        $query = new SearchQuery(
            text: 'acme waterproof trail running shoes',
            provider: 'static-fixture',
            locale: 'en_US',
            device: 'desktop',
            intent: 'category_product',
            expectedVisibility: true,
            priority: 'critical',
        );

        $report = (new VisibilityAnalyzer(
            searchProvider: new StaticSearchProvider([new SearchResultSet(
                query: $query,
                results: [new SearchResult(position: 1, url: self::PRODUCT_URL)],
            )]),
            urlMatcher: new DefaultUrlMatcher(),
            visibilityResultDetector: new VisibilityResultDetector(),
            pageFetcher: new FixturePageFetcher([
                self::PRODUCT_URL => new PageSnapshot(
                    requestedUrl: self::PRODUCT_URL,
                    finalUrl: self::PRODUCT_URL,
                    statusCode: 200,
                    headers: ['content-type' => ['text/html; charset=utf-8']],
                    body: $body,
                    contentType: 'text/html; charset=utf-8',
                    durationMs: 1,
                ),
            ]),
            pageParser: new DomPageParser(),
        ))->analyze($this->focusedFixtureProduct(), [$query]);

        return array_map(
            static fn (Finding $finding): string => $finding->code,
            $report->queryVisibilities[0]->findings,
        );
    }

    private function focusedFixtureProduct(): ProductSubject
    {
        return new ProductSubject(
            expectedUrl: self::PRODUCT_URL,
            id: 'demo-aurora-trail-shoe',
            name: 'Aurora Trail Shoe',
            brand: 'Acme Outdoor',
            category: 'Trail running shoes',
            expectedTerms: ['Aurora Trail Shoe', 'Acme Outdoor', 'waterproof trail running shoes'],
            commercialPriority: 'critical',
            price: 149.00,
            currency: 'USD',
            stockStatus: 'in_stock',
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, string>
     */
    private function summaryCategories(array $payload): array
    {
        return array_values(array_filter(
            array_map(
                static fn (array $entry): ?string => isset($entry['category']) && is_string($entry['category']) ? $entry['category'] : null,
                $this->summaryEntries($payload),
            ),
            static fn (?string $category): bool => $category !== null,
        ));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    private function summaryEntries(array $payload): array
    {
        $summary = $payload['summary'] ?? [];

        self::assertIsArray($summary);

        return array_merge(
            $this->summaryEntryList($summary, 'topProbableCauses'),
            $this->summaryEntryList($summary, 'topRecommendedActions'),
            $this->summaryEntryList($summary, 'evidenceReferences'),
        );
    }

    /**
     * @param array<string, mixed> $summary
     * @return array<int, array<string, mixed>>
     */
    private function summaryEntryList(array $summary, string $field): array
    {
        $entries = $summary[$field] ?? [];

        self::assertIsArray($entries);

        foreach ($entries as $entry) {
            self::assertIsArray($entry);
        }

        return $entries;
    }
}
