<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use VisibilityDetector\Core\Product\ProductSubject;
use VisibilityDetector\Core\Report\Finding;
use VisibilityDetector\Core\Report\FindingPrioritizer;
use VisibilityDetector\Core\Report\QueryVisibility;
use VisibilityDetector\Core\Report\ReportSummarizer;
use VisibilityDetector\Core\Search\SearchQuery;
use VisibilityDetector\Core\Url\UrlMatch;

final class ReportSummarizerTest extends TestCase
{
    public function test_visible_product_has_low_priority_summary(): void
    {
        $summary = $this->summarizer()->summarize($this->product(), [
            $this->queryVisibility(status: 'visible'),
        ]);

        self::assertSame('visible', $summary->overallStatus);
        self::assertSame('low', $summary->overallPriority);
        self::assertNull($summary->highestPriorityAffectedQuery);
        self::assertSame('product.visible_in_results', $summary->topRecommendedActions[0]['code']);
    }

    public function test_not_visible_expected_query_increases_priority(): void
    {
        $summary = $this->summarizer()->summarize($this->product(), [
            $this->queryVisibility(
                query: $this->query(text: 'buy widget', expectedVisibility: true, priority: 'high'),
                status: 'not_visible',
            ),
        ]);

        self::assertSame('not_visible', $summary->overallStatus);
        self::assertSame('high', $summary->overallPriority);
        self::assertSame('buy widget', $summary->highestPriorityAffectedQuery);
    }

    public function test_critical_product_commercial_priority_increases_overall_priority(): void
    {
        $summary = $this->summarizer()->summarize($this->product(commercialPriority: 'critical'), [
            $this->queryVisibility(
                query: $this->query(expectedVisibility: true),
                status: 'not_visible',
            ),
        ]);

        self::assertSame('critical', $summary->overallPriority);
    }

    public function test_noindex_outranks_weak_content_finding(): void
    {
        $summary = $this->summarizer()->summarize($this->product(), [
            $this->queryVisibility(findings: [
                $this->finding('content.description_too_thin', 'medium'),
                $this->finding('page.noindex_meta', 'medium'),
            ]),
        ]);

        self::assertSame('page.noindex_meta', $summary->topProbableCauses[0]['code']);
        self::assertSame('indexability_blocker', $summary->topProbableCauses[0]['category']);
    }

    public function test_canonical_issue_outranks_missing_description(): void
    {
        $summary = $this->summarizer()->summarize($this->product(), [
            $this->queryVisibility(findings: [
                $this->finding('content.description_missing', 'high'),
                $this->finding('canonical.points_to_other_url', 'medium'),
            ]),
        ]);

        self::assertSame('canonical.points_to_other_url', $summary->topProbableCauses[0]['code']);
        self::assertSame('canonical_blocker', $summary->topProbableCauses[0]['category']);
    }

    public function test_http_fetch_issue_outranks_noindex(): void
    {
        $summary = $this->summarizer()->summarize($this->product(), [
            $this->queryVisibility(findings: [
                $this->finding('page.noindex_meta', 'critical'),
                $this->finding('page.fetch_failed', 'high'),
            ]),
        ]);

        self::assertSame('page.fetch_failed', $summary->topProbableCauses[0]['code']);
        self::assertSame('availability_blocker', $summary->topProbableCauses[0]['category']);
    }

    public function test_structured_data_issue_appears_as_visibility_quality_issue(): void
    {
        $summary = $this->summarizer()->summarize($this->product(), [
            $this->queryVisibility(findings: [
                $this->finding('schema.product_missing', 'medium'),
            ]),
        ]);

        self::assertSame('schema.product_missing', $summary->topProbableCauses[0]['code']);
        self::assertSame('visibility_quality', $summary->topProbableCauses[0]['category']);
    }

    public function test_summary_prefers_primary_canonical_finding_for_duplicate_root_cause(): void
    {
        $summary = $this->summarizer()->summarize($this->product(), [
            $this->queryVisibility(findings: [
                $this->finding('page.canonical_mismatch', 'medium', evidence: ['canonicalUrl' => 'https://merchant.test/products/other-widget']),
                $this->finding('canonical.points_to_other_url', 'high', evidence: ['offendingCanonicalUrl' => 'https://merchant.test/products/other-widget']),
            ]),
        ]);

        self::assertSame(['canonical.points_to_other_url'], array_column($summary->topProbableCauses, 'code'));
        self::assertSame(['canonical.points_to_other_url'], array_column($summary->topRecommendedActions, 'code'));
    }

    public function test_summary_prefers_primary_product_schema_finding_for_duplicate_root_cause(): void
    {
        $summary = $this->summarizer()->summarize($this->product(), [
            $this->queryVisibility(findings: [
                $this->finding('page.product_schema_missing', 'medium', evidence: ['productSchemaCandidateCount' => 0]),
                $this->finding('schema.product_missing', 'high', evidence: ['productSchemaCandidateCount' => 0]),
            ]),
        ]);

        self::assertSame(['schema.product_missing'], array_column($summary->topProbableCauses, 'code'));
        self::assertSame(['schema.product_missing'], array_column($summary->topRecommendedActions, 'code'));
    }

    public function test_repeated_page_noindex_across_queries_appears_once_in_top_probable_causes(): void
    {
        $summary = $this->summarizer()->summarize($this->product(), [
            $this->queryVisibility(
                query: $this->query(text: 'alpha widget'),
                findings: [$this->finding('page.noindex_meta', 'high')],
            ),
            $this->queryVisibility(
                query: $this->query(text: 'beta widget'),
                findings: [$this->finding('page.noindex_meta', 'high')],
            ),
        ]);

        self::assertSame(['page.noindex_meta'], array_column($summary->topProbableCauses, 'code'));
    }

    public function test_repeated_page_noindex_does_not_hide_next_distinct_issue(): void
    {
        $summary = $this->summarizer()->summarize($this->product(), [
            $this->queryVisibility(
                query: $this->query(text: 'alpha widget'),
                findings: [
                    $this->finding('page.noindex_meta', 'high'),
                    $this->finding('canonical.points_to_other_url', 'high'),
                ],
            ),
            $this->queryVisibility(
                query: $this->query(text: 'beta widget'),
                findings: [$this->finding('page.noindex_meta', 'high')],
            ),
        ]);

        self::assertSame([
            'page.noindex_meta',
            'canonical.points_to_other_url',
        ], array_column($summary->topProbableCauses, 'code'));
    }

    public function test_deduplicated_page_issue_preserves_affected_queries(): void
    {
        $summary = $this->summarizer()->summarize($this->product(), [
            $this->queryVisibility(
                query: $this->query(text: 'beta widget'),
                findings: [$this->finding('page.noindex_meta', 'high')],
            ),
            $this->queryVisibility(
                query: $this->query(text: 'alpha widget'),
                findings: [$this->finding('page.noindex_meta', 'high')],
            ),
        ]);

        self::assertSame('alpha widget', $summary->topProbableCauses[0]['affectedQuery']);
        self::assertSame(['alpha widget', 'beta widget'], $summary->topProbableCauses[0]['affectedQueries']);
        self::assertSame(['alpha widget', 'beta widget'], $summary->evidenceReferences[0]['affectedQueries']);
    }

    public function test_query_specific_not_found_findings_are_not_collapsed_across_queries(): void
    {
        $summary = $this->summarizer()->summarize($this->product(), [
            $this->queryVisibility(
                query: $this->query(text: 'alpha widget'),
                findings: [$this->finding('product.not_found_in_results', 'medium')],
            ),
            $this->queryVisibility(
                query: $this->query(text: 'beta widget'),
                findings: [$this->finding('product.not_found_in_results', 'medium')],
            ),
        ]);

        self::assertSame([
            'product.not_found_in_results',
            'product.not_found_in_results',
        ], array_column($summary->topProbableCauses, 'code'));
        self::assertSame(['alpha widget', 'beta widget'], array_column($summary->topProbableCauses, 'affectedQuery'));
    }

    public function test_deterministic_ordering_when_findings_have_equal_priority(): void
    {
        $summary = $this->summarizer()->summarize($this->product(), [
            $this->queryVisibility(findings: [
                $this->finding('content.h1_missing_product_terms', 'medium'),
                $this->finding('content.description_missing', 'medium'),
                $this->finding('content.body_missing_product_terms', 'medium'),
            ]),
        ]);

        self::assertSame([
            'content.body_missing_product_terms',
            'content.description_missing',
            'content.h1_missing_product_terms',
        ], array_column($summary->topProbableCauses, 'code'));
    }

    public function test_equal_query_specific_findings_from_different_queries_sort_by_query_text(): void
    {
        $summary = $this->summarizer()->summarize($this->product(), [
            $this->queryVisibility(
                query: $this->query(text: 'z query'),
                findings: [$this->finding('product.not_found_in_results', 'medium')],
            ),
            $this->queryVisibility(
                query: $this->query(text: 'a query'),
                findings: [$this->finding('product.not_found_in_results', 'medium')],
            ),
        ]);

        self::assertSame(['a query', 'z query'], array_column($summary->topProbableCauses, 'affectedQuery'));
    }

    public function test_fully_equal_priority_code_and_query_falls_back_to_original_index(): void
    {
        $summary = $this->summarizer()->summarize($this->product(), [
            $this->queryVisibility(findings: [
                $this->finding('schema.offer_missing', 'medium', evidence: ['sequence' => 'first']),
                $this->finding('schema.offer_missing', 'medium', evidence: ['sequence' => 'second']),
            ]),
        ]);

        self::assertSame('first', $summary->evidenceReferences[0]['evidence']['sequence']);
        self::assertSame('second', $summary->evidenceReferences[1]['evidence']['sequence']);
    }

    public function test_summary_includes_top_recommended_actions(): void
    {
        $summary = $this->summarizer()->summarize($this->product(), [
            $this->queryVisibility(findings: [
                $this->finding('canonical.points_to_homepage', 'high', recommendation: 'Point canonical to the product page.'),
            ]),
        ]);

        self::assertSame('canonical.points_to_homepage', $summary->topRecommendedActions[0]['code']);
        self::assertSame('Point canonical to the product page.', $summary->topRecommendedActions[0]['action']);
    }

    public function test_summary_includes_evidence_references(): void
    {
        $summary = $this->summarizer()->summarize($this->product(), [
            $this->queryVisibility(findings: [
                $this->finding('page.http_status_not_ok', 'high', evidence: ['statusCode' => 404]),
            ]),
        ]);

        self::assertSame('page.http_status_not_ok', $summary->evidenceReferences[0]['code']);
        self::assertSame('widget', $summary->evidenceReferences[0]['affectedQuery']);
        self::assertSame(['statusCode' => 404], $summary->evidenceReferences[0]['evidence']);
    }

    public function test_prioritizer_does_not_mutate_findings(): void
    {
        $finding = $this->finding('page.fetch_failed', 'high', evidence: ['failureType' => 'timeout']);
        $before = $finding->toArray();

        (new FindingPrioritizer())->prioritize($finding, $this->queryVisibility(), $this->product());

        self::assertSame($before, $finding->toArray());
    }

    private function summarizer(): ReportSummarizer
    {
        return new ReportSummarizer();
    }

    private function product(?string $commercialPriority = null): ProductSubject
    {
        return new ProductSubject(
            expectedUrl: 'https://merchant.test/products/widget',
            name: 'Widget',
            commercialPriority: $commercialPriority,
        );
    }

    private function query(string $text = 'widget', ?bool $expectedVisibility = null, ?string $priority = null): SearchQuery
    {
        return new SearchQuery(
            text: $text,
            provider: 'google',
            expectedVisibility: $expectedVisibility,
            priority: $priority,
        );
    }

    /**
     * @param array<int, Finding> $findings
     */
    private function queryVisibility(?SearchQuery $query = null, string $status = 'visible', array $findings = []): QueryVisibility
    {
        return new QueryVisibility(
            query: $query ?? $this->query(),
            status: $status,
            urlMatch: new UrlMatch(
                matched: $status === 'visible',
                matchType: $status === 'visible' ? 'exact' : 'none',
                expectedUrl: 'https://merchant.test/products/widget',
                matchedUrl: $status === 'visible' ? 'https://merchant.test/products/widget' : null,
                matchedPosition: $status === 'visible' ? 1 : null,
            ),
            findings: $findings,
        );
    }

    private function finding(string $code, string $severity, array $evidence = [], ?string $recommendation = null): Finding
    {
        return new Finding(
            code: $code,
            severity: $severity,
            confidence: 1.0,
            message: 'Finding ' . $code,
            evidence: $evidence,
            recommendation: $recommendation,
        );
    }
}
