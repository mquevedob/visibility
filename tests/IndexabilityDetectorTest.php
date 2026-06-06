<?php

declare(strict_types=1);

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use VisibilityDetector\Core\Detector\DetectionContext;
use VisibilityDetector\Core\Detector\IndexabilityDetector;
use VisibilityDetector\Core\Page\PageSnapshot;
use VisibilityDetector\Core\Page\ParsedPage;
use VisibilityDetector\Core\Product\ProductSubject;
use VisibilityDetector\Core\Search\SearchQuery;
use VisibilityDetector\Core\Search\SearchResultSet;
use VisibilityDetector\Core\Url\UrlMatch;

final class IndexabilityDetectorTest extends TestCase
{
    public function test_fetch_failure_produces_finding(): void
    {
        $findings = $this->detector()->detect($this->context(
            pageSnapshot: $this->snapshot(failureType: 'timeout'),
        ));

        self::assertSame('page.fetch_failed', $findings[0]->code);
        self::assertSame('timeout', $findings[0]->evidence['pageSnapshot']['failureType']);
    }

    public function test_null_failure_type_does_not_produce_fetch_failed_finding(): void
    {
        $findings = $this->detector()->detect($this->context(
            pageSnapshot: $this->snapshot(failureType: null),
        ));

        self::assertNotContains('page.fetch_failed', $this->codes($findings));
    }

    public function test_non_2xx_http_status_produces_finding(): void
    {
        $findings = $this->detector()->detect($this->context(
            pageSnapshot: $this->snapshot(statusCode: 404),
        ));

        self::assertContains('page.http_status_not_ok', $this->codes($findings));
    }

    public function test_empty_body_produces_finding(): void
    {
        $findings = $this->detector()->detect($this->context(
            pageSnapshot: $this->snapshot(body: ''),
        ));

        self::assertContains('page.empty_body', $this->codes($findings));
    }

    public function test_non_html_content_produces_finding(): void
    {
        $findings = $this->detector()->detect($this->context(
            pageSnapshot: $this->snapshot(contentType: 'application/json'),
        ));

        self::assertContains('page.non_html_content', $this->codes($findings));
    }

    public function test_meta_noindex_produces_finding(): void
    {
        $findings = $this->detector()->detect($this->context(
            parsedPage: $this->parsedPage(robotsDirectives: ['index', 'noindex']),
        ));

        self::assertSame('page.noindex_meta', $findings[0]->code);
    }

    public function test_googlebot_meta_noindex_produces_finding(): void
    {
        $findings = $this->detector()->detect($this->context(
            parsedPage: $this->parsedPage(robotsDirectives: [' Googlebot: NoIndex ']),
        ));

        self::assertContains('page.noindex_meta', $this->codes($findings));
    }

    public function test_x_robots_noindex_produces_finding(): void
    {
        $findings = $this->detector()->detect($this->context(
            parsedPage: $this->parsedPage(xRobotsDirectives: ['noindex']),
        ));

        self::assertSame('page.noindex_x_robots', $findings[0]->code);
    }

    public function test_googlebot_x_robots_noindex_produces_finding(): void
    {
        $findings = $this->detector()->detect($this->context(
            parsedPage: $this->parsedPage(xRobotsDirectives: [' googlebot: noindex ']),
        ));

        self::assertContains('page.noindex_x_robots', $this->codes($findings));
    }

    public function test_unrelated_token_containing_noindex_does_not_produce_noindex_finding(): void
    {
        $findings = $this->detector()->detect($this->context(
            parsedPage: $this->parsedPage(
                robotsDirectives: ['x-noindex-test', 'not-none-token', 'x-unavailable_after: not-a-date'],
                xRobotsDirectives: ['x-noindex-test', 'not-none-token', 'x-unavailable_after: not-a-date'],
            ),
        ));

        self::assertNotContains('page.noindex_meta', $this->codes($findings));
        self::assertNotContains('page.noindex_x_robots', $this->codes($findings));
        self::assertNotContains('page.robots_none', $this->codes($findings));
        self::assertNotContains('page.unavailable_after_expired', $this->codes($findings));
        self::assertNotContains('page.unavailable_after_invalid', $this->codes($findings));
    }


    public function test_meta_robots_none_produces_finding(): void
    {
        $findings = $this->detector()->detect($this->context(
            parsedPage: $this->parsedPage(robotsDirectives: ['none']),
        ));

        self::assertSame('page.robots_none', $findings[0]->code);
        self::assertSame('meta_robots', $findings[0]->evidence['source']);
        self::assertSame('none', $findings[0]->evidence['directive']);
    }

    public function test_x_robots_none_produces_finding(): void
    {
        $findings = $this->detector()->detect($this->context(
            parsedPage: $this->parsedPage(xRobotsDirectives: ['none']),
        ));

        self::assertSame('page.robots_none', $findings[0]->code);
        self::assertSame('x_robots_tag', $findings[0]->evidence['source']);
        self::assertSame('none', $findings[0]->evidence['directive']);
    }

    public function test_bot_scoped_robots_none_produces_finding(): void
    {
        $findings = $this->detector()->detect($this->context(
            parsedPage: $this->parsedPage(robotsDirectives: ['googlebot: none']),
        ));

        self::assertContains('page.robots_none', $this->codes($findings));
    }

    public function test_expired_unavailable_after_from_meta_robots_produces_finding(): void
    {
        $findings = $this->detector()->detect($this->context(
            parsedPage: $this->parsedPage(robotsDirectives: ['unavailable_after: Wed, 21 Oct 2015 07:28:00 GMT']),
        ));

        self::assertSame('page.unavailable_after_expired', $findings[0]->code);
        self::assertSame('meta_robots', $findings[0]->evidence['source']);
        self::assertSame('unavailable_after: Wed, 21 Oct 2015 07:28:00 GMT', $findings[0]->evidence['directive']);
        self::assertSame('2015-10-21T07:28:00+00:00', $findings[0]->evidence['parsedDate']);
        self::assertSame('2020-01-01T00:00:00+00:00', $findings[0]->evidence['referenceDate']);
    }

    public function test_expired_unavailable_after_from_x_robots_tag_produces_finding(): void
    {
        $findings = $this->detector()->detect($this->context(
            parsedPage: $this->parsedPage(xRobotsDirectives: ['unavailable_after: Wed, 21 Oct 2015 07:28:00 GMT']),
        ));

        self::assertSame('page.unavailable_after_expired', $findings[0]->code);
        self::assertSame('x_robots_tag', $findings[0]->evidence['source']);
    }

    public function test_future_unavailable_after_does_not_produce_expired_finding(): void
    {
        $findings = $this->detector()->detect($this->context(
            parsedPage: $this->parsedPage(robotsDirectives: ['unavailable_after: Wed, 21 Oct 2030 07:28:00 GMT']),
        ));

        self::assertNotContains('page.unavailable_after_expired', $this->codes($findings));
        self::assertNotContains('page.unavailable_after_invalid', $this->codes($findings));
    }

    public function test_invalid_unavailable_after_produces_finding(): void
    {
        $findings = $this->detector()->detect($this->context(
            parsedPage: $this->parsedPage(robotsDirectives: ['unavailable_after: not-a-date']),
        ));

        self::assertSame('page.unavailable_after_invalid', $findings[0]->code);
        self::assertSame('unavailable_after: not-a-date', $findings[0]->evidence['directive']);
        self::assertSame('2020-01-01T00:00:00+00:00', $findings[0]->evidence['referenceDate']);
    }

    public function test_bot_scoped_unavailable_after_produces_finding(): void
    {
        $findings = $this->detector()->detect($this->context(
            parsedPage: $this->parsedPage(robotsDirectives: ['googlebot: unavailable_after: Wed, 21 Oct 2015 07:28:00 GMT']),
        ));

        self::assertContains('page.unavailable_after_expired', $this->codes($findings));
    }

    public function test_canonical_mismatch_produces_finding(): void
    {
        $findings = $this->detector()->detect($this->context(
            parsedPage: $this->parsedPage(canonicalUrl: 'https://merchant.test/products/other-widget'),
        ));

        self::assertSame('page.canonical_mismatch', $findings[0]->code);
        self::assertSame('https://merchant.test/products/other-widget', $findings[0]->evidence['canonicalUrl']);
    }

    public function test_no_page_evidence_produces_uncertain(): void
    {
        $findings = $this->detector()->detect($this->context());

        self::assertSame('page.indexability_uncertain', $findings[0]->code);
    }


    private function detector(): IndexabilityDetector
    {
        return new IndexabilityDetector(now: new DateTimeImmutable('2020-01-01 00:00:00 UTC'));
    }

    /**
     * @param array<int, \VisibilityDetector\Core\Report\Finding> $findings
     * @return array<int, string>
     */
    private function codes(array $findings): array
    {
        return array_map(static fn ($finding): string => $finding->code, $findings);
    }

    private function context(?PageSnapshot $pageSnapshot = null, ?ParsedPage $parsedPage = null): DetectionContext
    {
        $query = new SearchQuery(text: 'widget', provider: 'static');

        return new DetectionContext(
            product: new ProductSubject(
                expectedUrl: 'https://merchant.test/products/widget',
                acceptableUrlVariants: ['https://merchant.test/widget'],
            ),
            query: $query,
            resultSet: new SearchResultSet(query: $query),
            urlMatch: new UrlMatch(
                matched: false,
                matchType: 'none',
                expectedUrl: 'https://merchant.test/products/widget',
            ),
            pageSnapshot: $pageSnapshot,
            parsedPage: $parsedPage,
        );
    }

    private function snapshot(
        ?int $statusCode = 200,
        ?string $body = '<html><body>Widget</body></html>',
        ?string $contentType = 'text/html',
        ?string $failureType = 'none',
    ): PageSnapshot {
        return new PageSnapshot(
            requestedUrl: 'https://merchant.test/products/widget',
            finalUrl: 'https://merchant.test/products/widget',
            statusCode: $statusCode,
            body: $body,
            contentType: $contentType,
            failureType: $failureType,
        );
    }

    private function parsedPage(
        ?string $canonicalUrl = null,
        array $robotsDirectives = [],
        array $xRobotsDirectives = [],
    ): ParsedPage {
        return new ParsedPage(
            url: 'https://merchant.test/products/widget',
            canonicalUrl: $canonicalUrl,
            robotsDirectives: $robotsDirectives,
            xRobotsDirectives: $xRobotsDirectives,
        );
    }
}
