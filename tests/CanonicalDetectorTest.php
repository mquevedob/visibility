<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use VisibilityDetector\Core\Detector\CanonicalDetector;
use VisibilityDetector\Core\Detector\DetectionContext;
use VisibilityDetector\Core\Page\ParsedPage;
use VisibilityDetector\Core\Product\ProductSubject;
use VisibilityDetector\Core\Report\Finding;
use VisibilityDetector\Core\Search\SearchQuery;
use VisibilityDetector\Core\Search\SearchResultSet;
use VisibilityDetector\Core\Url\UrlMatch;

final class CanonicalDetectorTest extends TestCase
{
    public function test_clean_canonical_matching_expected_url_emits_no_findings(): void
    {
        $findings = $this->detector()->detect($this->context(
            parsedPage: $this->parsedPage(canonicalUrl: 'https://merchant.test/products/widget'),
        ));

        self::assertSame([], $findings);
    }

    public function test_clean_canonical_matching_acceptable_url_variant_emits_no_findings(): void
    {
        $findings = $this->detector()->detect($this->context(
            acceptableUrlVariants: ['https://merchant.test/products/widget?variant=blue'],
            parsedPage: $this->parsedPage(canonicalUrl: 'https://merchant.test/products/widget?variant=blue&utm_source=newsletter'),
        ));

        self::assertSame([], $findings);
    }

    public function test_canonical_mismatch_evidence_lists_expected_url_and_acceptable_variants(): void
    {
        $findings = $this->detector()->detect($this->context(
            acceptableUrlVariants: ['https://merchant.test/products/widget?variant=blue'],
            parsedPage: $this->parsedPage(canonicalUrl: 'https://merchant.test/products/other-widget'),
        ));
        $finding = $this->findingByCode($findings, 'canonical.points_to_other_url');

        self::assertSame('https://merchant.test/products/widget', $finding->evidence['expectedUrl']);
        self::assertSame(['https://merchant.test/products/widget?variant=blue'], $finding->evidence['acceptableUrlVariants']);
        self::assertSame('https://merchant.test/products/other-widget', $finding->evidence['canonicalUrl']);
        self::assertSame([
            'https://merchant.test/products/widget',
            'https://merchant.test/products/widget?variant=blue',
        ], $finding->evidence['normalizedAcceptedUrls']);
        self::assertSame('canonicalUrl compared against expectedUrl plus acceptableUrlVariants', $finding->evidence['comparisonPolicy']);
    }

    public function test_missing_canonical_emits_missing_finding(): void
    {
        $findings = $this->detector()->detect($this->context(
            parsedPage: $this->parsedPage(canonicalUrl: null),
        ));

        self::assertSame('canonical.missing', $findings[0]->code);
        self::assertSame('medium', $findings[0]->severity);
        self::assertSame('canonical_url_missing', $findings[0]->evidence['reason']);
    }

    public function test_blank_canonical_emits_missing_finding(): void
    {
        $findings = $this->detector()->detect($this->context(
            parsedPage: $this->parsedPage(canonicalUrl: '   '),
        ));

        self::assertSame('canonical.missing', $findings[0]->code);
    }

    public function test_invalid_canonical_emits_invalid_finding(): void
    {
        $findings = $this->detector()->detect($this->context(
            parsedPage: $this->parsedPage(canonicalUrl: 'https://merchant test/products/widget'),
        ));

        self::assertSame('canonical.invalid', $findings[0]->code);
        self::assertSame('high', $findings[0]->severity);
        self::assertSame('https://merchant test/products/widget', $findings[0]->evidence['canonicalUrl']);
    }

    public function test_relative_canonical_emits_relative_finding(): void
    {
        $findings = $this->detector()->detect($this->context(
            parsedPage: $this->parsedPage(canonicalUrl: '../widget'),
        ));

        self::assertSame('canonical.relative', $findings[0]->code);
        self::assertSame('medium', $findings[0]->severity);
        self::assertSame('../widget', $findings[0]->evidence['canonicalUrl']);
    }

    public function test_canonical_pointing_to_different_product_emits_other_url_finding(): void
    {
        $findings = $this->detector()->detect($this->context(
            parsedPage: $this->parsedPage(canonicalUrl: 'https://merchant.test/products/other-widget'),
        ));

        self::assertContains('canonical.points_to_other_url', $this->codes($findings));
        self::assertSame(
            'https://merchant.test/products/other-widget',
            $this->findingByCode($findings, 'canonical.points_to_other_url')->evidence['normalizedCanonicalUrl'],
        );
    }

    public function test_canonical_pointing_to_homepage_emits_homepage_finding(): void
    {
        $findings = $this->detector()->detect($this->context(
            parsedPage: $this->parsedPage(canonicalUrl: 'https://merchant.test/'),
        ));

        self::assertContains('canonical.points_to_homepage', $this->codes($findings));
        self::assertContains('canonical.points_to_other_url', $this->codes($findings));
    }


    public function test_second_relative_canonical_emits_relative_finding(): void
    {
        $findings = $this->detector()->detect($this->context(
            parsedPage: $this->parsedPage(
                canonicalUrl: 'https://merchant.test/products/widget',
                canonicalUrls: [
                    'https://merchant.test/products/widget',
                    '/products/widget',
                ],
            ),
        ));

        self::assertContains('canonical.relative', $this->codes($findings));
        self::assertSame('/products/widget', $this->findingByCode($findings, 'canonical.relative')->evidence['offendingCanonicalUrl']);
    }

    public function test_second_invalid_canonical_emits_invalid_finding(): void
    {
        $findings = $this->detector()->detect($this->context(
            parsedPage: $this->parsedPage(
                canonicalUrl: 'https://merchant.test/products/widget',
                canonicalUrls: [
                    'https://merchant.test/products/widget',
                    'https://merchant test/products/widget',
                ],
            ),
        ));

        self::assertContains('canonical.invalid', $this->codes($findings));
        self::assertSame('https://merchant test/products/widget', $this->findingByCode($findings, 'canonical.invalid')->evidence['offendingCanonicalUrl']);
    }

    public function test_multiple_conflicting_canonical_urls_emit_conflict_finding(): void
    {
        $findings = $this->detector()->detect($this->context(
            parsedPage: $this->parsedPage(
                canonicalUrl: 'https://merchant.test/products/widget',
                canonicalUrls: [
                    'https://merchant.test/products/widget',
                    'https://merchant.test/products/other-widget',
                ],
            ),
        ));

        self::assertContains('canonical.multiple_conflicting', $this->codes($findings));
        self::assertSame(
            [
                'https://merchant.test/products/widget',
                'https://merchant.test/products/other-widget',
            ],
            $this->findingByCode($findings, 'canonical.multiple_conflicting')->evidence['normalizedCanonicalUrls'],
        );
    }

    public function test_multiple_duplicate_equivalent_canonical_urls_do_not_emit_conflict_finding(): void
    {
        $findings = $this->detector()->detect($this->context(
            parsedPage: $this->parsedPage(
                canonicalUrl: 'https://merchant.test/products/widget',
                canonicalUrls: [
                    'https://merchant.test/products/widget/',
                    'https://MERCHANT.test/products/widget?utm_source=newsletter',
                ],
            ),
        ));

        self::assertNotContains('canonical.multiple_conflicting', $this->codes($findings));
    }

    public function test_missing_parsed_page_emits_no_canonical_findings(): void
    {
        $findings = $this->detector()->detect($this->context(parsedPage: null));

        self::assertSame([], $findings);
    }

    /**
     * @param array<int, Finding> $findings
     * @return array<int, string>
     */
    private function codes(array $findings): array
    {
        return array_map(static fn (Finding $finding): string => $finding->code, $findings);
    }

    /**
     * @param array<int, Finding> $findings
     */
    private function findingByCode(array $findings, string $code): Finding
    {
        foreach ($findings as $finding) {
            if ($finding->code === $code) {
                return $finding;
            }
        }

        self::fail('Finding was not emitted: ' . $code);
    }

    private function detector(): CanonicalDetector
    {
        return new CanonicalDetector();
    }

    /**
     * @param array<int, string> $acceptableUrlVariants
     */
    private function context(array $acceptableUrlVariants = [], ?ParsedPage $parsedPage = null): DetectionContext
    {
        $query = new SearchQuery(text: 'widget', provider: 'static');

        return new DetectionContext(
            product: new ProductSubject(
                expectedUrl: 'https://merchant.test/products/widget',
                acceptableUrlVariants: $acceptableUrlVariants,
            ),
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
     * @param array<int, string> $canonicalUrls
     */
    private function parsedPage(?string $canonicalUrl, array $canonicalUrls = []): ParsedPage
    {
        return new ParsedPage(
            url: 'https://merchant.test/products/widget',
            canonicalUrl: $canonicalUrl,
            canonicalUrls: $canonicalUrls,
        );
    }
}
