<?php

declare(strict_types=1);

namespace VisibilityDetector\Core\Analyzer;

use InvalidArgumentException;
use VisibilityDetector\Core\Detector\CanonicalDetector;
use VisibilityDetector\Core\Detector\ContentAlignmentDetector;
use VisibilityDetector\Core\Detector\DetectionContext;
use VisibilityDetector\Core\Detector\IndexabilityDetector;
use VisibilityDetector\Core\Detector\MetadataDetector;
use VisibilityDetector\Core\Detector\StructuredDataDetector;
use VisibilityDetector\Core\Detector\VisibilityResultDetector;
use VisibilityDetector\Core\Page\PageFetcher;
use VisibilityDetector\Core\Page\PageParser;
use VisibilityDetector\Core\Page\PageSnapshot;
use VisibilityDetector\Core\Page\ParsedPage;
use VisibilityDetector\Core\Product\ProductSubject;
use VisibilityDetector\Core\Report\Finding;
use VisibilityDetector\Core\Report\QueryVisibility;
use VisibilityDetector\Core\Report\ReportSummarizer;
use VisibilityDetector\Core\Report\VisibilityReport;
use VisibilityDetector\Core\Search\SearchProvider;
use VisibilityDetector\Core\Search\SearchQuery;
use VisibilityDetector\Core\Search\SearchResultSet;
use VisibilityDetector\Core\Url\UrlMatcher;

final readonly class VisibilityAnalyzer
{
    public function __construct(
        private SearchProvider $searchProvider,
        private UrlMatcher $urlMatcher,
        private VisibilityResultDetector $visibilityResultDetector,
        private ?PageFetcher $pageFetcher = null,
        private ?PageParser $pageParser = null,
        private IndexabilityDetector $indexabilityDetector = new IndexabilityDetector(),
        private MetadataDetector $metadataDetector = new MetadataDetector(),
        private CanonicalDetector $canonicalDetector = new CanonicalDetector(),
        private StructuredDataDetector $structuredDataDetector = new StructuredDataDetector(),
        private ContentAlignmentDetector $contentAlignmentDetector = new ContentAlignmentDetector(),
        private ReportSummarizer $reportSummarizer = new ReportSummarizer(),
    ) {
    }

    /**
     * @param array<int, SearchQuery> $queries
     */
    public function analyze(ProductSubject $product, array $queries): VisibilityReport
    {
        $this->validateQueries($queries);

        $resultSets = $this->searchProvider->search($product, $queries);
        $resultSetsByQuery = $this->resultSetsByQuery($queries, $resultSets);
        $queryEvidence = [];
        $firstMatchedUrl = null;

        foreach ($queries as $query) {
            $resultSet = $resultSetsByQuery[$this->queryKey($query)] ?? new SearchResultSet(query: $query);
            $urlMatch = $this->urlMatcher->match($product, $resultSet);

            if ($firstMatchedUrl === null && $urlMatch->matchedUrl !== null && trim($urlMatch->matchedUrl) !== '') {
                $firstMatchedUrl = $urlMatch->matchedUrl;
            }

            $queryEvidence[] = [
                'query' => $query,
                'resultSet' => $resultSet,
                'urlMatch' => $urlMatch,
            ];
        }

        $urlToFetch = $this->urlToFetch($product, $firstMatchedUrl);
        $pageSnapshot = null;
        $parsedPage = null;
        $sharedWarnings = [];
        $sharedFindingFactory = null;

        if ($this->pageFetcher instanceof PageFetcher) {
            $pageSnapshot = $this->pageFetcher->fetch($urlToFetch);
            $sharedWarnings = array_values(array_merge($sharedWarnings, $pageSnapshot->warnings));
        } else {
            $sharedFindingFactory = fn (SearchQuery $query): Finding => $this->pageFetchSkippedFinding($product, $query, $urlToFetch);
        }

        $parseSkippedFindingFactory = null;

        if ($this->pageParser instanceof PageParser && $this->hasBodyEvidence($pageSnapshot)) {
            $parsedPage = $this->pageParser->parse($pageSnapshot);
            $sharedWarnings = array_values(array_merge($sharedWarnings, $parsedPage->parserWarnings));
        } elseif (!$this->pageParser instanceof PageParser) {
            $parseSkippedFindingFactory = fn (SearchQuery $query): Finding => $this->pageParseSkippedFinding($product, $query, $pageSnapshot, 'No PageParser was supplied to the analyzer.');
        } elseif ($pageSnapshot instanceof PageSnapshot) {
            $parseSkippedFindingFactory = fn (SearchQuery $query): Finding => $this->pageParseSkippedFinding($product, $query, $pageSnapshot, 'The supplied PageSnapshot did not include body evidence to parse.');
        }

        $queryVisibilities = [];
        $reportWarnings = $sharedWarnings;

        foreach ($queryEvidence as $evidence) {
            /** @var SearchQuery $query */
            $query = $evidence['query'];
            /** @var SearchResultSet $resultSet */
            $resultSet = $evidence['resultSet'];
            $urlMatch = $evidence['urlMatch'];
            $orchestrationFindings = [];
            $warnings = array_values(array_merge($resultSet->warnings, $resultSet->limitations, $sharedWarnings));

            if ($sharedFindingFactory !== null) {
                $finding = $sharedFindingFactory($query);
                $orchestrationFindings[] = $finding;
                $warnings[] = $finding->message;
            }

            if ($parseSkippedFindingFactory !== null) {
                $finding = $parseSkippedFindingFactory($query);
                $orchestrationFindings[] = $finding;
                $warnings[] = $finding->message;
            }

            $context = new DetectionContext(
                product: $product,
                query: $query,
                resultSet: $resultSet,
                urlMatch: $urlMatch,
                pageSnapshot: $pageSnapshot,
                parsedPage: $parsedPage,
            );

            $queryVisibility = $this->visibilityResultDetector->queryVisibility($context);
            $detectorFindings = [];

            if ($pageSnapshot instanceof PageSnapshot || $parsedPage instanceof ParsedPage) {
                $detectorFindings = array_merge($detectorFindings, $this->indexabilityDetector->detect($context));
            }

            if ($parsedPage instanceof ParsedPage) {
                $detectorFindings = array_merge($detectorFindings, $this->metadataDetector->detect($context));
                $detectorFindings = array_merge($detectorFindings, $this->canonicalDetector->detect($context));
                $detectorFindings = array_merge($detectorFindings, $this->structuredDataDetector->detect($context));
                $detectorFindings = array_merge($detectorFindings, $this->contentAlignmentDetector->detect($context));
            }

            $queryVisibilities[] = new QueryVisibility(
                query: $queryVisibility->query,
                status: $queryVisibility->status,
                urlMatch: $queryVisibility->urlMatch,
                matchedResult: $queryVisibility->matchedResult,
                findings: $this->suppressDuplicateFindings(array_values(array_merge($queryVisibility->findings, $orchestrationFindings, $detectorFindings))),
                warnings: array_values(array_unique(array_merge($queryVisibility->warnings, $warnings))),
            );
            $reportWarnings = array_values(array_unique(array_merge($reportWarnings, $warnings)));
        }

        return new VisibilityReport(
            product: $product,
            queryVisibilities: $queryVisibilities,
            pageSnapshot: $pageSnapshot,
            parsedPage: $parsedPage,
            warnings: array_values(array_unique($reportWarnings)),
            summary: $this->reportSummarizer->summarize($product, $queryVisibilities),
        );
    }


    /**
     * @param array<int, Finding> $findings
     * @return array<int, Finding>
     */
    private function suppressDuplicateFindings(array $findings): array
    {
        return $this->suppressDuplicateFinding(
            $this->suppressDuplicateFinding(
                $findings,
                duplicateCode: 'page.canonical_mismatch',
                primaryCode: 'canonical.points_to_other_url',
            ),
            duplicateCode: 'page.product_schema_missing',
            primaryCode: 'schema.product_missing',
        );
    }

    /**
     * @param array<int, Finding> $findings
     * @return array<int, Finding>
     */
    private function suppressDuplicateFinding(array $findings, string $duplicateCode, string $primaryCode): array
    {
        $duplicate = null;
        $primaryIndex = null;

        foreach ($findings as $index => $finding) {
            if ($finding->code === $duplicateCode) {
                $duplicate = $finding;
            }

            if ($finding->code === $primaryCode) {
                $primaryIndex = $index;
            }
        }

        if (!$duplicate instanceof Finding || $primaryIndex === null) {
            return $findings;
        }

        $primary = $findings[$primaryIndex];
        $findings[$primaryIndex] = new Finding(
            code: $primary->code,
            severity: $primary->severity,
            confidence: $primary->confidence,
            message: $primary->message,
            evidence: $primary->evidence + [
                'suppressedDuplicateFindings' => [[
                    'code' => $duplicate->code,
                    'message' => $duplicate->message,
                    'evidence' => $duplicate->evidence,
                    'recommendation' => $duplicate->recommendation,
                ]],
            ],
            recommendation: $primary->recommendation,
        );

        return array_values(array_filter(
            $findings,
            static fn (Finding $finding): bool => $finding->code !== $duplicateCode,
        ));
    }

    /**
     * @param array<int, SearchQuery> $queries
     */
    private function validateQueries(array $queries): void
    {
        if ($queries === []) {
            throw new InvalidArgumentException('queries must contain at least one SearchQuery.');
        }

        foreach ($queries as $query) {
            if (!$query instanceof SearchQuery) {
                throw new InvalidArgumentException('queries must contain only SearchQuery objects.');
            }
        }
    }

    /**
     * @param array<int, SearchQuery> $queries
     * @param array<int, SearchResultSet> $resultSets
     * @return array<string, SearchResultSet>
     */
    private function resultSetsByQuery(array $queries, array $resultSets): array
    {
        $indexed = [];

        foreach ($resultSets as $resultSet) {
            if (!$resultSet instanceof SearchResultSet) {
                throw new InvalidArgumentException('SearchProvider must return only SearchResultSet objects.');
            }

            $indexed[$this->queryKey($resultSet->query)] = $resultSet;
        }

        foreach ($queries as $index => $query) {
            if (!array_key_exists($this->queryKey($query), $indexed) && isset($resultSets[$index]) && $resultSets[$index] instanceof SearchResultSet) {
                $indexed[$this->queryKey($query)] = $resultSets[$index];
            }
        }

        return $indexed;
    }

    private function queryKey(SearchQuery $query): string
    {
        return implode("\n", [
            $query->text,
            $query->provider,
            $query->locale ?? '',
            $query->device ?? '',
        ]);
    }

    private function urlToFetch(ProductSubject $product, ?string $matchedUrl): string
    {
        return $matchedUrl !== null && trim($matchedUrl) !== '' ? $matchedUrl : $product->expectedUrl;
    }

    private function hasBodyEvidence(?PageSnapshot $pageSnapshot): bool
    {
        return $pageSnapshot instanceof PageSnapshot && $pageSnapshot->body !== null && trim($pageSnapshot->body) !== '';
    }

    private function pageFetchSkippedFinding(ProductSubject $product, SearchQuery $query, string $urlToFetch): Finding
    {
        return new Finding(
            code: 'analyzer.page_fetch_skipped',
            severity: 'info',
            confidence: 1.0,
            message: 'Page fetching was skipped because no PageFetcher was supplied to the analyzer.',
            evidence: [
                'product' => ['expectedUrl' => $product->expectedUrl],
                'query' => $query->toArray(),
                'urlToFetch' => $urlToFetch,
                'reason' => 'page_fetcher_not_supplied',
            ],
            recommendation: 'Supply a fixture-backed or caller-injected PageFetcher to include page-level diagnostics.',
        );
    }

    private function pageParseSkippedFinding(ProductSubject $product, SearchQuery $query, ?PageSnapshot $pageSnapshot, string $reason): Finding
    {
        return new Finding(
            code: 'analyzer.page_parse_skipped',
            severity: 'info',
            confidence: 1.0,
            message: 'Page parsing was skipped: ' . $reason,
            evidence: [
                'product' => ['expectedUrl' => $product->expectedUrl],
                'query' => $query->toArray(),
                'pageSnapshot' => $pageSnapshot?->toArray(),
                'reason' => $reason,
            ],
            recommendation: 'Supply a PageParser and a PageSnapshot with HTML body evidence to include parsed-page diagnostics.',
        );
    }

}
