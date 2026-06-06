<?php

declare(strict_types=1);

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use VisibilityDetector\Core\Detector\DetectionContext;
use VisibilityDetector\Core\Detector\IndexabilityDetector;
use VisibilityDetector\Core\Page\DomPageParser;
use VisibilityDetector\Core\Page\PageParser;
use VisibilityDetector\Core\Page\PageSnapshot;
use VisibilityDetector\Core\Page\ParsedPage;
use VisibilityDetector\Core\Product\ProductSubject;
use VisibilityDetector\Core\Search\SearchQuery;
use VisibilityDetector\Core\Search\SearchResultSet;
use VisibilityDetector\Core\Url\UrlMatch;

final class DomPageParserTest extends TestCase
{
    private DomPageParser $parser;

    protected function setUp(): void
    {
        $this->parser = new DomPageParser();
    }

    public function test_parses_title_meta_description_canonical_url_and_h1(): void
    {
        $parsed = $this->parser->parse($this->snapshot(<<<'HTML'
            <html>
                <head>
                    <title>Widget Product</title>
                    <meta name="description" content="A useful widget for testing.">
                    <link rel="canonical" href="https://merchant.test/products/widget">
                </head>
                <body><h1>Buy Widget</h1></body>
            </html>
            HTML));

        self::assertInstanceOf(PageParser::class, $this->parser);
        self::assertSame('Widget Product', $parsed->title);
        self::assertSame('A useful widget for testing.', $parsed->metaDescription);
        self::assertSame('https://merchant.test/products/widget', $parsed->canonicalUrl);
        self::assertSame('Buy Widget', $parsed->h1);
    }

    public function test_extracts_multiple_canonical_link_hrefs(): void
    {
        $parsed = $this->parser->parse($this->snapshot(<<<'HTML'
            <html><head>
                <link rel="canonical" href="https://merchant.test/products/widget">
                <link rel="canonical" href="https://merchant.test/products/widget?variant=blue">
            </head><body>Widget</body></html>
            HTML));

        self::assertSame('https://merchant.test/products/widget', $parsed->canonicalUrl);
        self::assertSame([
            'https://merchant.test/products/widget',
            'https://merchant.test/products/widget?variant=blue',
        ], $parsed->canonicalUrls);
    }

    public function test_parses_meta_robots(): void
    {
        $parsed = $this->parser->parse($this->snapshot('<html><head><meta name="robots" content="noindex, nofollow, max-snippet:0"></head><body>Widget</body></html>'));

        self::assertSame(['noindex', 'nofollow', 'max-snippet:0'], $parsed->robotsDirectives);
    }

    public function test_preserves_meta_robots_unavailable_after_http_date(): void
    {
        $parsed = $this->parser->parse($this->snapshot('<html><head><meta name="robots" content="noindex, unavailable_after: Wed, 21 Oct 2015 07:28:00 GMT, nofollow"></head><body>Widget</body></html>'));

        self::assertSame([
            'noindex',
            'unavailable_after: Wed, 21 Oct 2015 07:28:00 GMT',
            'nofollow',
        ], $parsed->robotsDirectives);
    }

    public function test_preserves_bot_scoped_meta_robots_unavailable_after_http_date(): void
    {
        $parsed = $this->parser->parse($this->snapshot('<html><head><meta name="robots" content="googlebot: unavailable_after: Wed, 21 Oct 2015 07:28:00 GMT, nofollow"></head><body>Widget</body></html>'));

        self::assertSame([
            'googlebot: unavailable_after: Wed, 21 Oct 2015 07:28:00 GMT',
            'nofollow',
        ], $parsed->robotsDirectives);
    }

    public function test_parses_x_robots_tag_header(): void
    {
        $parsed = $this->parser->parse($this->snapshot(
            '<html><body>Widget</body></html>',
            headers: ['X-Robots-Tag' => ['noindex, noarchive', 'googlebot: nofollow']],
        ));

        self::assertSame(['noindex', 'noarchive', 'googlebot: nofollow'], $parsed->xRobotsDirectives);
    }

    public function test_preserves_x_robots_tag_unavailable_after_http_date(): void
    {
        $parsed = $this->parser->parse($this->snapshot(
            '<html><body>Widget</body></html>',
            headers: ['X-Robots-Tag' => ['noindex, unavailable_after: Wed, 21 Oct 2015 07:28:00 GMT, nofollow']],
        ));

        self::assertSame([
            'noindex',
            'unavailable_after: Wed, 21 Oct 2015 07:28:00 GMT',
            'nofollow',
        ], $parsed->xRobotsDirectives);
    }

    public function test_preserves_bot_scoped_x_robots_tag_unavailable_after_http_date(): void
    {
        $parsed = $this->parser->parse($this->snapshot(
            '<html><body>Widget</body></html>',
            headers: ['X-Robots-Tag' => ['googlebot: unavailable_after: Wed, 21 Oct 2015 07:28:00 GMT, nofollow']],
        ));

        self::assertSame([
            'googlebot: unavailable_after: Wed, 21 Oct 2015 07:28:00 GMT',
            'nofollow',
        ], $parsed->xRobotsDirectives);
    }

    public function test_indexability_detector_detects_parsed_bot_scoped_unavailable_after(): void
    {
        $parsed = $this->parser->parse($this->snapshot('<html><head><meta name="robots" content="googlebot: unavailable_after: Wed, 21 Oct 2015 07:28:00 GMT"></head><body>Widget</body></html>'));
        $findings = (new IndexabilityDetector(now: new DateTimeImmutable('2020-01-01 00:00:00 UTC')))->detect($this->context($parsed));

        self::assertContains('page.unavailable_after_expired', array_map(static fn ($finding): string => $finding->code, $findings));
    }

    public function test_extracts_hreflang_links(): void
    {
        $parsed = $this->parser->parse($this->snapshot(<<<'HTML'
            <html><head>
                <link rel="alternate" hreflang="en-us" href="https://merchant.test/en/products/widget">
                <link rel="alternate" hreflang="es-es" href="https://merchant.test/es/products/widget">
            </head><body>Widget</body></html>
            HTML));

        self::assertSame([
            ['hreflang' => 'en-us', 'url' => 'https://merchant.test/en/products/widget'],
            ['hreflang' => 'es-es', 'url' => 'https://merchant.test/es/products/widget'],
        ], $parsed->hreflangLinks);
    }

    public function test_extracts_links_and_headings(): void
    {
        $parsed = $this->parser->parse($this->snapshot(<<<'HTML'
            <html><body>
                <h1>Widget</h1>
                <h2>Details</h2>
                <h3>Shipping</h3>
                <a href="/cart" rel="nofollow">Add to cart</a>
                <a href="https://merchant.test/policies/shipping">Shipping policy</a>
            </body></html>
            HTML));

        self::assertSame([
            ['level' => 1, 'text' => 'Widget'],
            ['level' => 2, 'text' => 'Details'],
            ['level' => 3, 'text' => 'Shipping'],
        ], $parsed->headings);
        self::assertSame([
            ['url' => '/cart', 'text' => 'Add to cart', 'rel' => 'nofollow'],
            ['url' => 'https://merchant.test/policies/shipping', 'text' => 'Shipping policy', 'rel' => null],
        ], $parsed->links);
    }

    public function test_extracts_valid_json_ld_product_and_offer_schema_candidates(): void
    {
        $parsed = $this->parser->parse($this->snapshot(<<<'HTML'
            <html><head>
                <script type="application/ld+json">
                {
                    "@context": "https://schema.org",
                    "@type": "Product",
                    "name": "Widget",
                    "offers": {
                        "@type": "Offer",
                        "price": "19.99",
                        "priceCurrency": "USD",
                        "availability": "https://schema.org/InStock"
                    }
                }
                </script>
            </head><body>Widget</body></html>
            HTML));

        self::assertCount(1, $parsed->jsonLdBlocks);
        self::assertSame(['Product', 'Offer'], $parsed->schemaTypes);
        self::assertCount(1, $parsed->productSchemaCandidates);
        self::assertSame('Widget', $parsed->productSchemaCandidates[0]['name']);
        self::assertCount(1, $parsed->offerSchemaCandidates);
        self::assertSame('19.99', $parsed->offerSchemaCandidates[0]['price']);
    }

    public function test_extracts_top_level_json_ld_array_schema_candidates(): void
    {
        $parsed = $this->parser->parse($this->snapshot(<<<'HTML'
            <html><head>
                <script type="application/ld+json">
                [
                    {
                        "@context": "https://schema.org",
                        "@type": "Product",
                        "name": "Widget Array Product",
                        "sku": "WIDGET-ARRAY"
                    },
                    {
                        "@context": "https://schema.org",
                        "@type": "BreadcrumbList",
                        "itemListElement": []
                    }
                ]
                </script>
            </head><body>Widget</body></html>
            HTML));

        self::assertCount(1, $parsed->jsonLdBlocks);
        self::assertSame('Widget Array Product', $parsed->jsonLdBlocks[0][0]['name']);
        self::assertSame(['Product', 'BreadcrumbList'], $parsed->schemaTypes);
        self::assertCount(1, $parsed->productSchemaCandidates);
        self::assertSame('Widget Array Product', $parsed->productSchemaCandidates[0]['name']);
    }

    public function test_extracts_json_ld_graph_product_and_offer_schema_candidates(): void
    {
        $parsed = $this->parser->parse($this->snapshot(<<<'HTML'
            <html><head>
                <script type="application/ld+json">
                {
                    "@context": "https://schema.org",
                    "@graph": [
                        {
                            "@type": "Product",
                            "name": "Graph Widget",
                            "sku": "GRAPH-1"
                        },
                        {
                            "@type": "Offer",
                            "price": "29.99",
                            "priceCurrency": "USD",
                            "availability": "https://schema.org/InStock"
                        }
                    ]
                }
                </script>
            </head><body>Widget</body></html>
            HTML));

        self::assertSame(['Product', 'Offer'], $parsed->schemaTypes);
        self::assertCount(1, $parsed->productSchemaCandidates);
        self::assertSame('Graph Widget', $parsed->productSchemaCandidates[0]['name']);
        self::assertCount(1, $parsed->offerSchemaCandidates);
        self::assertSame('29.99', $parsed->offerSchemaCandidates[0]['price']);
    }

    public function test_malformed_json_ld_creates_parser_warning(): void
    {
        $parsed = $this->parser->parse($this->snapshot('<html><head><script type="application/ld+json">{"@type":"Product",</script></head><body>Widget</body></html>'));

        self::assertSame([], $parsed->jsonLdBlocks);
        self::assertNotEmpty($parsed->parserWarnings);
        self::assertStringContainsString('Malformed JSON-LD block', $parsed->parserWarnings[0]);
    }

    public function test_malformed_html_does_not_throw(): void
    {
        $parsed = $this->parser->parse($this->snapshot('<html><head><title>Widget</title><body><h1>Widget'));

        self::assertSame('Widget', $parsed->title);
        self::assertSame('Widget', $parsed->h1);
    }

    public function test_empty_body_returns_warning(): void
    {
        $parsed = $this->parser->parse($this->snapshot(''));

        self::assertNotEmpty($parsed->parserWarnings);
        self::assertStringContainsString('body is empty', $parsed->parserWarnings[0]);
    }

    public function test_empty_body_preserves_x_robots_tag_header(): void
    {
        $parsed = $this->parser->parse($this->snapshot('', headers: ['X-Robots-Tag' => ['noindex']]));

        self::assertSame(['noindex'], $parsed->xRobotsDirectives);
        self::assertNotEmpty($parsed->parserWarnings);
        self::assertStringContainsString('body is empty', $parsed->parserWarnings[0]);
    }

    public function test_non_html_content_type_returns_warning(): void
    {
        $parsed = $this->parser->parse($this->snapshot('{"name":"Widget"}', contentType: 'application/json'));

        self::assertNull($parsed->title);
        self::assertNotEmpty($parsed->parserWarnings);
        self::assertStringContainsString('contentType is not HTML', $parsed->parserWarnings[0]);
    }

    public function test_non_html_content_type_preserves_x_robots_tag_header(): void
    {
        $parsed = $this->parser->parse($this->snapshot(
            '{"name":"Widget"}',
            headers: ['X-Robots-Tag' => ['noindex']],
            contentType: 'application/json',
        ));

        self::assertSame(['noindex'], $parsed->xRobotsDirectives);
        self::assertNotEmpty($parsed->parserWarnings);
        self::assertStringContainsString('contentType is not HTML', $parsed->parserWarnings[0]);
    }

    public function test_body_text_summary_is_populated_and_reasonably_trimmed(): void
    {
        $longText = str_repeat('Useful widget content ', 40);
        $parsed = $this->parser->parse($this->snapshot('<html><body><script>ignored()</script><p>' . $longText . '</p></body></html>'));

        self::assertNotNull($parsed->bodyTextSummary);
        self::assertStringStartsWith('Useful widget content', $parsed->bodyTextSummary);
        self::assertStringNotContainsString('ignored()', $parsed->bodyTextSummary);
        self::assertLessThanOrEqual(500, strlen($parsed->bodyTextSummary));
    }

    private function context(ParsedPage $parsedPage): DetectionContext
    {
        $query = new SearchQuery(text: 'widget', provider: 'static');

        return new DetectionContext(
            product: new ProductSubject(expectedUrl: 'https://merchant.test/products/widget'),
            query: $query,
            resultSet: new SearchResultSet(query: $query),
            urlMatch: new UrlMatch(
                matched: false,
                matchType: 'none',
                expectedUrl: 'https://merchant.test/products/widget',
            ),
            parsedPage: $parsedPage,
        );
    }

    /**
     * @param array<string, mixed> $headers
     */
    private function snapshot(string $body, array $headers = [], ?string $contentType = 'text/html'): PageSnapshot
    {
        return new PageSnapshot(
            requestedUrl: 'https://merchant.test/products/widget',
            finalUrl: 'https://merchant.test/products/widget',
            statusCode: 200,
            headers: $headers,
            body: $body,
            contentType: $contentType,
        );
    }
}
