<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use VisibilityDetector\Adapters\Static\FixturePageFetcher;
use VisibilityDetector\Adapters\Static\StaticSearchProvider;
use VisibilityDetector\Core\Analyzer\VisibilityAnalyzer;
use VisibilityDetector\Core\Detector\IndexabilityDetector;
use VisibilityDetector\Core\Detector\MetadataDetector;
use VisibilityDetector\Core\Detector\VisibilityResultDetector;
use VisibilityDetector\Core\Page\PageFetcher;
use VisibilityDetector\Core\Page\PageParser;
use VisibilityDetector\Core\Page\PageSnapshot;
use VisibilityDetector\Core\Page\ParsedPage;
use VisibilityDetector\Core\Product\ProductSubject;
use VisibilityDetector\Core\Search\SearchQuery;
use VisibilityDetector\Core\Search\SearchResult;
use VisibilityDetector\Core\Search\SearchResultSet;
use VisibilityDetector\Core\Url\DefaultUrlMatcher;

final class VisibilityAnalyzerTest extends TestCase
{
    public function test_analyzer_returns_visible_when_search_result_url_matches_product_url(): void
    {
        $query = $this->query();
        $report = $this->analyzer(resultSets: [
            $this->resultSet($query, [new SearchResult(position: 1, url: 'https://merchant.test/products/widget')]),
        ])->analyze($this->product(), [$query]);

        self::assertSame('visible', $report->queryVisibilities[0]->status);
        self::assertSame('product.visible_in_results', $report->queryVisibilities[0]->findings[0]->code);
        self::assertSame(1, $report->summary?->visibleCount);
    }

    public function test_analyzer_returns_not_visible_when_search_results_exist_but_no_url_match(): void
    {
        $query = $this->query();
        $report = $this->analyzer(resultSets: [
            $this->resultSet($query, [new SearchResult(position: 1, url: 'https://competitor.test/products/widget')]),
        ])->analyze($this->product(), [$query]);

        self::assertSame('not_visible', $report->queryVisibilities[0]->status);
        self::assertSame('product.not_found_in_results', $report->queryVisibilities[0]->findings[0]->code);
    }

    public function test_analyzer_returns_uncertain_when_search_provider_returns_warnings(): void
    {
        $query = $this->query();
        $report = $this->analyzer(resultSets: [
            $this->resultSet(
                $query,
                [new SearchResult(position: 1, url: 'https://competitor.test/products/widget')],
                warnings: ['provider returned partial results'],
            ),
        ])->analyze($this->product(), [$query]);

        self::assertSame('uncertain', $report->queryVisibilities[0]->status);
        self::assertContains('provider returned partial results', $report->queryVisibilities[0]->warnings);
        self::assertContains('provider returned partial results', $report->warnings);
    }

    public function test_analyzer_uses_matched_url_for_page_fetch_when_url_match_exists(): void
    {
        $query = $this->query();
        $fetcher = new VisibilityAnalyzerRecordingFetcher();
        $report = $this->analyzer(
            resultSets: [$this->resultSet($query, [new SearchResult(position: 1, url: 'https://merchant.test/products/widget?utm_source=test')])],
            pageFetcher: $fetcher,
            pageParser: new VisibilityAnalyzerParsedPageParser(),
        )->analyze($this->product(), [$query]);

        self::assertSame(['https://merchant.test/products/widget?utm_source=test'], $fetcher->requestedUrls);
        self::assertSame('https://merchant.test/products/widget?utm_source=test', $report->pageSnapshot?->requestedUrl);
    }

    public function test_analyzer_falls_back_to_expected_url_for_page_fetch_when_no_match_exists(): void
    {
        $query = $this->query();
        $fetcher = new VisibilityAnalyzerRecordingFetcher();
        $this->analyzer(
            resultSets: [$this->resultSet($query, [new SearchResult(position: 1, url: 'https://competitor.test/products/widget')])],
            pageFetcher: $fetcher,
            pageParser: new VisibilityAnalyzerParsedPageParser(),
        )->analyze($this->product(), [$query]);

        self::assertSame(['https://merchant.test/products/widget'], $fetcher->requestedUrls);
    }

    public function test_analyzer_fetches_and_parses_once_for_multiple_queries_using_first_matched_url(): void
    {
        $firstQuery = $this->query(text: 'generic widget');
        $secondQuery = $this->query(text: 'buy widget');
        $fetcher = new VisibilityAnalyzerRecordingFetcher();
        $parser = new VisibilityAnalyzerParsedPageParser(new ParsedPage(url: 'https://merchant.test/products/widget?utm_source=test', robotsDirectives: ['noindex']));

        $report = $this->analyzer(
            resultSets: [
                $this->resultSet($firstQuery, [new SearchResult(position: 1, url: 'https://competitor.test/products/widget')]),
                $this->resultSet($secondQuery, [new SearchResult(position: 2, url: 'https://merchant.test/products/widget?utm_source=test')]),
            ],
            pageFetcher: $fetcher,
            pageParser: $parser,
        )->analyze($this->product(), [$firstQuery, $secondQuery]);

        self::assertSame(['https://merchant.test/products/widget?utm_source=test'], $fetcher->requestedUrls);
        self::assertSame(1, $parser->parseCount);
        self::assertSame('https://merchant.test/products/widget?utm_source=test', $report->pageSnapshot?->requestedUrl);
        self::assertSame($report->pageSnapshot?->finalUrl, $report->parsedPage?->url);
        self::assertContains('page.noindex_meta', $this->findingCodes($report->queryVisibilities[0]));
        self::assertContains('page.noindex_meta', $this->findingCodes($report->queryVisibilities[1]));
    }

    public function test_analyzer_fetches_once_using_expected_url_when_no_query_matches(): void
    {
        $firstQuery = $this->query(text: 'generic widget');
        $secondQuery = $this->query(text: 'best widget');
        $fetcher = new VisibilityAnalyzerRecordingFetcher();

        $this->analyzer(
            resultSets: [
                $this->resultSet($firstQuery, [new SearchResult(position: 1, url: 'https://competitor.test/products/widget')]),
                $this->resultSet($secondQuery, [new SearchResult(position: 2, url: 'https://competitor.test/products/widget-2')]),
            ],
            pageFetcher: $fetcher,
            pageParser: new VisibilityAnalyzerParsedPageParser(),
        )->analyze($this->product(), [$firstQuery, $secondQuery]);

        self::assertSame(['https://merchant.test/products/widget'], $fetcher->requestedUrls);
    }

    public function test_analyzer_includes_indexability_findings_from_parsed_and_fetched_page_evidence(): void
    {
        $query = $this->query();
        $report = $this->analyzer(
            resultSets: [$this->resultSet($query, [new SearchResult(position: 1, url: 'https://merchant.test/products/widget')])],
            pageFetcher: new FixturePageFetcher([
                'https://merchant.test/products/widget' => [
                    'statusCode' => 200,
                    'contentType' => 'text/html',
                    'headers' => ['x-robots-tag' => ['noindex']],
                    'body' => '<html><head><meta name="robots" content="noindex"></head><body><h1>Widget</h1></body></html>',
                ],
            ]),
            pageParser: new VisibilityAnalyzerParsedPageParser(new ParsedPage(
                url: 'https://merchant.test/products/widget',
                robotsDirectives: ['noindex'],
            )),
        )->analyze($this->product(), [$query]);

        self::assertContains('page.noindex_meta', $this->findingCodes($report->queryVisibilities[0]));
    }

    public function test_analyzer_includes_metadata_findings_from_parsed_page_evidence(): void
    {
        $query = $this->query();
        $report = $this->analyzer(
            resultSets: [$this->resultSet($query, [new SearchResult(position: 1, url: 'https://merchant.test/products/widget')])],
            pageFetcher: new VisibilityAnalyzerRecordingFetcher(),
            pageParser: new VisibilityAnalyzerParsedPageParser(new ParsedPage(url: 'https://merchant.test/products/widget')),
        )->analyze($this->product(), [$query]);

        self::assertContains('page.title_missing', $this->findingCodes($report->queryVisibilities[0]));
        self::assertContains('page.product_schema_missing', $this->findingCodes($report->queryVisibilities[0]));
    }

    public function test_analyzer_report_includes_structured_data_detector_findings(): void
    {
        $query = $this->query();
        $report = $this->analyzer(
            resultSets: [$this->resultSet($query, [new SearchResult(position: 1, url: 'https://merchant.test/products/widget')])],
            pageFetcher: new VisibilityAnalyzerRecordingFetcher(),
            pageParser: new VisibilityAnalyzerParsedPageParser(new ParsedPage(
                url: 'https://merchant.test/products/widget',
                title: 'Widget',
                metaDescription: 'Widget page',
                h1: 'Widget',
            )),
        )->analyze($this->product(), [$query]);

        self::assertContains('schema.product_missing', $this->findingCodes($report->queryVisibilities[0]));
    }

    public function test_analyzer_preserves_parser_warnings(): void
    {
        $query = $this->query();
        $report = $this->analyzer(
            resultSets: [$this->resultSet($query, [new SearchResult(position: 1, url: 'https://merchant.test/products/widget')])],
            pageFetcher: new VisibilityAnalyzerRecordingFetcher(),
            pageParser: new VisibilityAnalyzerParsedPageParser(new ParsedPage(
                url: 'https://merchant.test/products/widget',
                parserWarnings: ['malformed json-ld'],
            )),
        )->analyze($this->product(), [$query]);

        self::assertContains('malformed json-ld', $report->queryVisibilities[0]->warnings);
        self::assertContains('malformed json-ld', $report->warnings);
    }


    public function test_analyzer_report_includes_canonical_detector_findings(): void
    {
        $query = $this->query();
        $report = $this->analyzer(
            resultSets: [$this->resultSet($query, [new SearchResult(position: 1, url: 'https://merchant.test/products/widget')])],
            pageFetcher: new VisibilityAnalyzerRecordingFetcher(),
            pageParser: new VisibilityAnalyzerParsedPageParser(new ParsedPage(
                url: 'https://merchant.test/products/widget',
                title: 'Widget',
                metaDescription: 'Widget page',
                canonicalUrl: 'https://merchant.test/products/other-widget',
                h1: 'Widget',
                productSchemaCandidates: [['@type' => 'Product']],
                offerSchemaCandidates: [['@type' => 'Offer']],
            )),
        )->analyze($this->product(), [$query]);

        self::assertContains('canonical.points_to_other_url', $this->findingCodes($report->queryVisibilities[0]));
    }


    public function test_analyzer_report_includes_content_alignment_detector_findings(): void
    {
        $query = $this->query();
        $report = $this->analyzer(
            resultSets: [$this->resultSet($query, [new SearchResult(position: 1, url: 'https://merchant.test/products/widget')])],
            pageFetcher: new VisibilityAnalyzerRecordingFetcher(),
            pageParser: new VisibilityAnalyzerParsedPageParser(new ParsedPage(
                url: 'https://merchant.test/products/widget',
                title: 'Generic product',
                metaDescription: 'A complete product description with enough visible characters for this deterministic check.',
                h1: 'Generic product',
                bodyTextSummary: 'Generic body copy.',
                productSchemaCandidates: [['@type' => 'Product']],
                offerSchemaCandidates: [['@type' => 'Offer']],
            )),
        )->analyze($this->product(expectedTerms: ['Premium Widget']), [$query]);

        self::assertContains('content.title_missing_product_terms', $this->findingCodes($report->queryVisibilities[0]));
    }

    public function test_analyzer_can_run_without_page_fetcher_and_emits_deterministic_skipped_finding(): void
    {
        $query = $this->query();
        $report = $this->analyzer(
            resultSets: [$this->resultSet($query, [new SearchResult(position: 1, url: 'https://merchant.test/products/widget')])],
            pageFetcher: null,
            pageParser: new VisibilityAnalyzerParsedPageParser(),
        )->analyze($this->product(), [$query]);

        self::assertSame('visible', $report->queryVisibilities[0]->status);
        self::assertContains('analyzer.page_fetch_skipped', $this->findingCodes($report->queryVisibilities[0]));
    }

    public function test_analyzer_can_run_without_page_parser_and_emits_deterministic_skipped_finding(): void
    {
        $query = $this->query();
        $report = $this->analyzer(
            resultSets: [$this->resultSet($query, [new SearchResult(position: 1, url: 'https://merchant.test/products/widget')])],
            pageFetcher: new VisibilityAnalyzerRecordingFetcher(),
            pageParser: null,
        )->analyze($this->product(), [$query]);

        self::assertContains('analyzer.page_parse_skipped', $this->findingCodes($report->queryVisibilities[0]));
    }

    public function test_analyzer_handles_multiple_search_query_objects_in_one_report(): void
    {
        $firstQuery = $this->query(text: 'widget');
        $secondQuery = $this->query(text: 'best widget');
        $report = $this->analyzer(resultSets: [
            $this->resultSet($firstQuery, [new SearchResult(position: 1, url: 'https://merchant.test/products/widget')]),
            $this->resultSet($secondQuery, [new SearchResult(position: 1, url: 'https://competitor.test/products/widget')]),
        ])->analyze($this->product(), [$firstQuery, $secondQuery]);

        self::assertCount(2, $report->queryVisibilities);
        self::assertSame('visible', $report->queryVisibilities[0]->status);
        self::assertSame('not_visible', $report->queryVisibilities[1]->status);
    }

    public function test_summary_counts_visible_not_visible_and_uncertain_correctly(): void
    {
        $visibleQuery = $this->query(text: 'visible widget');
        $notVisibleQuery = $this->query(text: 'missing widget');
        $uncertainQuery = $this->query(text: 'partial widget');
        $report = $this->analyzer(resultSets: [
            $this->resultSet($visibleQuery, [new SearchResult(position: 1, url: 'https://merchant.test/products/widget')]),
            $this->resultSet($notVisibleQuery, [new SearchResult(position: 1, url: 'https://competitor.test/products/widget')]),
            $this->resultSet(
                $uncertainQuery,
                [new SearchResult(position: 1, url: 'https://competitor.test/products/widget-2')],
                warnings: ['partial'],
            ),
        ])->analyze($this->product(), [$visibleQuery, $notVisibleQuery, $uncertainQuery]);

        self::assertSame(3, $report->summary?->totalQueries);
        self::assertSame(1, $report->summary?->visibleCount);
        self::assertSame(1, $report->summary?->notVisibleCount);
        self::assertSame(1, $report->summary?->uncertainCount);
    }

    private function analyzer(array $resultSets, ?PageFetcher $pageFetcher = null, ?PageParser $pageParser = null): VisibilityAnalyzer
    {
        return new VisibilityAnalyzer(
            searchProvider: new StaticSearchProvider($resultSets),
            urlMatcher: new DefaultUrlMatcher(),
            visibilityResultDetector: new VisibilityResultDetector(),
            pageFetcher: $pageFetcher,
            pageParser: $pageParser,
            indexabilityDetector: new IndexabilityDetector(now: new DateTimeImmutable('2020-01-01T00:00:00+00:00')),
            metadataDetector: new MetadataDetector(),
        );
    }

    /**
     * @param array<int, string> $expectedTerms
     */
    private function product(array $expectedTerms = []): ProductSubject
    {
        return new ProductSubject(expectedUrl: 'https://merchant.test/products/widget', expectedTerms: $expectedTerms);
    }

    private function query(string $text = 'buy widget'): SearchQuery
    {
        return new SearchQuery(text: $text, provider: 'google');
    }

    /**
     * @param array<int, SearchResult> $results
     * @param array<int, string> $warnings
     */
    private function resultSet(SearchQuery $query, array $results, array $warnings = []): SearchResultSet
    {
        return new SearchResultSet(query: $query, results: $results, warnings: $warnings);
    }

    /**
     * @return array<int, string>
     */
    private function findingCodes(VisibilityDetector\Core\Report\QueryVisibility $visibility): array
    {
        return array_map(static fn (VisibilityDetector\Core\Report\Finding $finding): string => $finding->code, $visibility->findings);
    }
}

final class VisibilityAnalyzerRecordingFetcher implements PageFetcher
{
    /** @var array<int, string> */
    public array $requestedUrls = [];

    public function fetch(string $url): PageSnapshot
    {
        $this->requestedUrls[] = $url;

        return new PageSnapshot(
            requestedUrl: $url,
            finalUrl: $url,
            statusCode: 200,
            headers: [],
            body: '<html><head><title>Widget</title></head><body><h1>Widget</h1></body></html>',
            contentType: 'text/html',
        );
    }
}

final class VisibilityAnalyzerParsedPageParser implements PageParser
{
    public int $parseCount = 0;

    public function __construct(private ?ParsedPage $parsedPage = null)
    {
    }

    public function parse(PageSnapshot $snapshot): ParsedPage
    {
        ++$this->parseCount;

        return $this->parsedPage ?? new ParsedPage(
            url: $snapshot->finalUrl ?? $snapshot->requestedUrl,
            title: 'Widget',
            metaDescription: 'Widget page',
            h1: 'Widget',
            productSchemaCandidates: [['@type' => 'Product']],
            offerSchemaCandidates: [['@type' => 'Offer']],
        );
    }
}
