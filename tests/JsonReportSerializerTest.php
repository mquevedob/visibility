<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use VisibilityDetector\Core\Page\PageSnapshot;
use VisibilityDetector\Core\Page\ParsedPage;
use VisibilityDetector\Core\Product\ProductSubject;
use VisibilityDetector\Core\Report\Finding;
use VisibilityDetector\Core\Report\JsonReportSerializer;
use VisibilityDetector\Core\Report\QueryVisibility;
use VisibilityDetector\Core\Report\ReportSummary;
use VisibilityDetector\Core\Report\VisibilityReport;
use VisibilityDetector\Core\Search\SearchQuery;
use VisibilityDetector\Core\Search\SearchResult;
use VisibilityDetector\Core\Url\UrlMatch;

final class JsonReportSerializerTest extends TestCase
{
    public function test_serializer_emits_valid_json_with_generated_at(): void
    {
        $json = $this->serializer()->serialize(
            $this->report(),
            new DateTimeImmutable('2026-06-07 10:15:30', new DateTimeZone('UTC')),
        );

        self::assertJson($json);

        $payload = $this->decode($json);

        self::assertSame('2026-06-07T10:15:30+00:00', $payload['generatedAt']);
    }

    public function test_generated_at_is_deterministic_when_supplied(): void
    {
        $generatedAt = new DateTimeImmutable('2026-06-07 10:15:30', new DateTimeZone('UTC'));

        $first = $this->serializer()->serialize($this->report(), $generatedAt);
        $second = $this->serializer()->serialize($this->report(), $generatedAt);

        self::assertSame($first, $second);
    }

    public function test_supplied_generated_at_is_normalized_to_utc(): void
    {
        $json = $this->serializer()->serialize(
            $this->report(),
            new DateTimeImmutable('2026-06-07 12:15:30', new DateTimeZone('Europe/Madrid')),
        );

        self::assertSame('2026-06-07T10:15:30+00:00', $this->decode($json)['generatedAt']);
    }

    public function test_explicit_generated_at_overrides_report_generated_at_and_normalizes_to_utc(): void
    {
        $json = $this->serializer()->serialize(
            $this->report(generatedAt: '2025-01-01T00:00:00+00:00'),
            new DateTimeImmutable('2026-06-07 12:15:30', new DateTimeZone('Europe/Madrid')),
        );

        self::assertSame('2026-06-07T10:15:30+00:00', $this->decode($json)['generatedAt']);
    }

    public function test_serializer_preserves_existing_report_generated_at_without_override(): void
    {
        $json = $this->serializer()->serialize(
            $this->report(generatedAt: '2025-01-01T00:00:00-05:00'),
        );

        self::assertSame('2025-01-01T00:00:00-05:00', $this->decode($json)['generatedAt']);
    }

    public function test_serializer_generates_current_time_only_when_report_has_no_generated_at(): void
    {
        $before = time();

        $json = $this->serializer()->serialize($this->report());

        $after = time();
        $payload = $this->decode($json);
        $generatedAt = new DateTimeImmutable($payload['generatedAt']);

        self::assertNotSame('2025-01-01T00:00:00+00:00', $payload['generatedAt']);
        self::assertGreaterThanOrEqual($before, $generatedAt->getTimestamp());
        self::assertLessThanOrEqual($after, $generatedAt->getTimestamp());
    }

    public function test_json_contains_product_expected_url_and_commercial_context(): void
    {
        $payload = $this->serializedPayload();

        self::assertSame('https://merchant.test/products/café-widget', $payload['product']['expectedUrl']);
        self::assertSame('critical', $payload['product']['commercialPriority']);
        self::assertSame('high_margin_launch', $payload['product']['commercialValue']);
        self::assertSame(49.99, $payload['product']['price']);
        self::assertSame('USD', $payload['product']['currency']);
        self::assertSame('in_stock', $payload['product']['stockStatus']);
    }

    public function test_json_contains_query_visibilities_and_findings(): void
    {
        $payload = $this->serializedPayload();
        $queryVisibility = $payload['queryVisibilities'][0];

        self::assertSame('buy café widget', $queryVisibility['query']['text']);
        self::assertSame('commercial', $queryVisibility['query']['intent']);
        self::assertTrue($queryVisibility['query']['expectedVisibility']);
        self::assertSame('critical', $queryVisibility['query']['priority']);
        self::assertSame('launch query', $queryVisibility['query']['reason']);
        self::assertSame('visible', $queryVisibility['status']);
        self::assertArrayHasKey('visibilityHealth', $queryVisibility);
        self::assertSame('content.description_missing', $queryVisibility['findings'][0]['code']);
        self::assertSame('Parsed meta description is empty.', $queryVisibility['findings'][0]['evidence']['parsedPage']);
    }

    public function test_json_contains_url_evidence_policy_roles(): void
    {
        $payload = $this->serializedPayload();

        self::assertSame('https://merchant.test/products/café-widget', $payload['urlEvidence']['expectedUrl']);
        self::assertSame(['https://merchant.test/products/café-widget'], $payload['urlEvidence']['matchedUrls']);
        self::assertSame('https://merchant.test/products/café-widget', $payload['urlEvidence']['requestedUrl']);
        self::assertSame('https://merchant.test/products/café-widget', $payload['urlEvidence']['finalUrl']);
        self::assertSame('https://merchant.test/products/café-widget', $payload['urlEvidence']['canonicalUrl']);
    }

    public function test_json_contains_page_snapshot_evidence(): void
    {
        $payload = $this->serializedPayload();

        self::assertSame('https://merchant.test/products/café-widget', $payload['pageSnapshot']['requestedUrl']);
        self::assertSame(200, $payload['pageSnapshot']['statusCode']);
        self::assertSame(['canonical checked'], $payload['pageSnapshot']['warnings']);
        self::assertStringContainsString('Café Widget', $payload['pageSnapshot']['body']);
    }

    public function test_json_contains_parsed_page_evidence(): void
    {
        $payload = $this->serializedPayload();

        self::assertSame('Café Widget — Buy Today', $payload['parsedPage']['title']);
        self::assertSame('https://merchant.test/products/café-widget', $payload['parsedPage']['canonicalUrl']);
        self::assertSame(['Product'], $payload['parsedPage']['schemaTypes']);
        self::assertSame(['missing alternate locale'], $payload['parsedPage']['parserWarnings']);
    }

    public function test_json_contains_warnings(): void
    {
        $payload = $this->serializedPayload();

        self::assertSame(['report warning'], $payload['warnings']);
        self::assertSame(['query warning'], $payload['queryVisibilities'][0]['warnings']);
    }

    public function test_json_contains_query_visibility_health(): void
    {
        $payload = $this->serializedPayload();

        self::assertSame('at_risk', $payload['queryVisibilities'][0]['visibilityHealth']);
    }

    public function test_json_contains_summary(): void
    {
        $payload = $this->serializedPayload();
        $summary = $payload['summary'];

        self::assertSame('visible', $summary['overallStatus']);
        self::assertSame('high', $summary['overallPriority']);
        self::assertSame(['content.description_missing'], array_column($summary['topProbableCauses'], 'code'));
        self::assertSame(['Improve meta description.'], array_column($summary['topRecommendedActions'], 'action'));
        self::assertSame(['parsedPage.metaDescription'], $summary['evidenceReferences']);
    }

    public function test_json_preserves_unicode_text(): void
    {
        $json = $this->serializer()->serialize(
            $this->report(),
            new DateTimeImmutable('2026-06-07 10:15:30', new DateTimeZone('UTC')),
        );

        self::assertStringContainsString('Café Widget', $json);
        self::assertStringContainsString('buy café widget', $json);
    }

    public function test_json_preserves_urls_without_escaping_slashes(): void
    {
        $json = $this->serializer()->serialize(
            $this->report(),
            new DateTimeImmutable('2026-06-07 10:15:30', new DateTimeZone('UTC')),
        );

        self::assertStringContainsString('https://merchant.test/products/café-widget', $json);
        self::assertStringNotContainsString('https:\/\/merchant.test\/products', $json);
    }

    public function test_serialization_propagates_json_throw_on_error_failures(): void
    {
        $this->expectException(JsonException::class);

        $this->serializer()->serialize(
            new VisibilityReport(
                product: new ProductSubject(
                    expectedUrl: 'https://merchant.test/products/widget',
                    commercialValue: INF,
                ),
            ),
            new DateTimeImmutable('2026-06-07 10:15:30', new DateTimeZone('UTC')),
        );
    }

    private function serializedPayload(): array
    {
        return $this->decode($this->serializer()->serialize(
            $this->report(),
            new DateTimeImmutable('2026-06-07 10:15:30', new DateTimeZone('UTC')),
        ));
    }

    private function report(?string $generatedAt = null): VisibilityReport
    {
        $query = new SearchQuery(
            text: 'buy café widget',
            provider: 'google',
            locale: 'en_US',
            device: 'desktop',
            intent: 'commercial',
            expectedVisibility: true,
            priority: 'critical',
            reason: 'launch query',
        );
        $result = new SearchResult(
            position: 1,
            url: 'https://merchant.test/products/café-widget',
            title: 'Café Widget — Merchant',
            snippet: 'Buy the café widget today.',
        );
        $finding = new Finding(
            code: 'content.description_missing',
            severity: 'medium',
            confidence: 0.9,
            message: 'Meta description is missing.',
            evidence: ['parsedPage' => 'Parsed meta description is empty.'],
            recommendation: 'Add a descriptive meta description.',
        );

        return new VisibilityReport(
            product: new ProductSubject(
                expectedUrl: 'https://merchant.test/products/café-widget',
                id: 'cafe-widget',
                name: 'Café Widget',
                brand: 'Demo Brand',
                category: 'Kitchen',
                expectedTerms: ['café widget'],
                commercialPriority: 'critical',
                commercialValue: 'high_margin_launch',
                price: 49.99,
                currency: 'USD',
                stockStatus: 'in_stock',
            ),
            queryVisibilities: [new QueryVisibility(
                query: $query,
                status: 'visible',
                urlMatch: new UrlMatch(
                    matched: true,
                    matchType: 'exact',
                    expectedUrl: 'https://merchant.test/products/café-widget',
                    matchedUrl: 'https://merchant.test/products/café-widget',
                    matchedPosition: 1,
                    matchedResult: $result,
                    evidence: ['rule' => 'exact_url'],
                ),
                matchedResult: $result,
                findings: [$finding],
                warnings: ['query warning'],
            )],
            pageSnapshot: new PageSnapshot(
                requestedUrl: 'https://merchant.test/products/café-widget',
                finalUrl: 'https://merchant.test/products/café-widget',
                statusCode: 200,
                headers: ['content-type' => ['text/html; charset=utf-8']],
                body: '<html><body>Café Widget</body></html>',
                contentType: 'text/html',
                durationMs: 12,
                warnings: ['canonical checked'],
            ),
            parsedPage: new ParsedPage(
                url: 'https://merchant.test/products/café-widget',
                title: 'Café Widget — Buy Today',
                canonicalUrl: 'https://merchant.test/products/café-widget',
                h1: 'Café Widget',
                schemaTypes: ['Product'],
                bodyTextSummary: 'Café Widget product detail page.',
                parserWarnings: ['missing alternate locale'],
            ),
            summaryFindings: [$finding],
            warnings: ['report warning'],
            generatedAt: $generatedAt,
            summary: new ReportSummary(
                overallStatus: 'visible',
                overallPriority: 'high',
                message: 'Product is visible with content improvements recommended.',
                topProbableCauses: [[
                    'code' => 'content.description_missing',
                    'category' => 'visibility_quality',
                ]],
                topRecommendedActions: [[
                    'code' => 'content.description_missing',
                    'action' => 'Improve meta description.',
                ]],
                evidenceReferences: ['parsedPage.metaDescription'],
                totalQueries: 1,
                visibleCount: 1,
            ),
        );
    }

    private function serializer(): JsonReportSerializer
    {
        return new JsonReportSerializer();
    }

    private function decode(string $json): array
    {
        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }
}
