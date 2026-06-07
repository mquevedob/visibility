<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use VisibilityDetector\Core\Detector\ContentAlignmentDetector;
use VisibilityDetector\Core\Detector\DetectionContext;
use VisibilityDetector\Core\Page\ParsedPage;
use VisibilityDetector\Core\Product\ProductSubject;
use VisibilityDetector\Core\Report\Finding;
use VisibilityDetector\Core\Search\SearchQuery;
use VisibilityDetector\Core\Search\SearchResultSet;
use VisibilityDetector\Core\Url\UrlMatch;

final class ContentAlignmentDetectorTest extends TestCase
{
    public function test_missing_parsed_page_emits_no_findings(): void
    {
        $findings = $this->detect(['Widget'], null);

        self::assertSame([], $findings);
    }

    public function test_empty_expected_terms_emits_no_term_alignment_findings(): void
    {
        $findings = $this->detect([], $this->page(
            title: 'Generic page',
            metaDescription: 'A complete product description with enough visible characters for this check.',
            h1: 'Generic heading',
            bodyTextSummary: 'Generic body copy.',
        ));

        self::assertNotContains('content.title_missing_product_terms', $this->codes($findings));
        self::assertNotContains('content.h1_missing_product_terms', $this->codes($findings));
        self::assertNotContains('content.body_missing_product_terms', $this->codes($findings));
    }

    public function test_title_missing_expected_terms_emits_title_finding(): void
    {
        $findings = $this->detect(['Widget'], $this->page(title: 'Generic product'));

        self::assertContains('content.title_missing_product_terms', $this->codes($findings));
    }

    public function test_title_matching_one_expected_term_emits_no_title_finding(): void
    {
        $findings = $this->detect(['Widget', 'Premium'], $this->page(title: 'Premium product'));

        self::assertNotContains('content.title_missing_product_terms', $this->codes($findings));
    }

    public function test_h1_missing_expected_terms_emits_h1_finding(): void
    {
        $findings = $this->detect(['Widget'], $this->page(h1: 'Generic product'));

        self::assertContains('content.h1_missing_product_terms', $this->codes($findings));
    }

    public function test_h1_matching_one_expected_term_emits_no_h1_finding(): void
    {
        $findings = $this->detect(['Widget', 'Premium'], $this->page(h1: 'Premium product'));

        self::assertNotContains('content.h1_missing_product_terms', $this->codes($findings));
    }

    public function test_missing_meta_description_emits_description_missing(): void
    {
        $findings = $this->detect([], $this->page(metaDescription: null));

        self::assertContains('content.description_missing', $this->codes($findings));
    }

    public function test_short_meta_description_emits_description_too_thin(): void
    {
        $findings = $this->detect([], $this->page(metaDescription: 'Short description.'));

        self::assertContains('content.description_too_thin', $this->codes($findings));
    }

    public function test_short_non_ascii_meta_description_counts_visible_characters(): void
    {
        $findings = $this->detect([], $this->page(metaDescription: 'Árbol'));
        $finding = $this->findingByCode($findings, 'content.description_too_thin');

        self::assertNotNull($finding);

        if (function_exists('mb_strlen')) {
            self::assertSame(5, $finding->evidence['visibleCharacterCount']);
            self::assertNotSame(strlen('Árbol'), $finding->evidence['visibleCharacterCount']);
        } else {
            self::assertSame(strlen('Árbol'), $finding->evidence['visibleCharacterCount']);
        }
    }

    public function test_missing_meta_description_does_not_also_emit_description_too_thin(): void
    {
        $findings = $this->detect([], $this->page(metaDescription: '   '));

        self::assertContains('content.description_missing', $this->codes($findings));
        self::assertNotContains('content.description_too_thin', $this->codes($findings));
    }

    public function test_body_missing_expected_terms_emits_body_finding(): void
    {
        $findings = $this->detect(['Widget'], $this->page(bodyTextSummary: 'Generic body copy.'));

        self::assertContains('content.body_missing_product_terms', $this->codes($findings));
    }

    public function test_body_matching_one_expected_term_emits_no_body_finding(): void
    {
        $findings = $this->detect(['Widget', 'Premium'], $this->page(bodyTextSummary: 'This copy describes a premium product.'));

        self::assertNotContains('content.body_missing_product_terms', $this->codes($findings));
    }

    public function test_matching_is_case_insensitive(): void
    {
        $findings = $this->detect(['Premium Widget'], $this->page(
            title: 'premium widget',
            h1: 'PREMIUM WIDGET',
            bodyTextSummary: 'Shop the Premium Widget today.',
        ));

        self::assertNotContains('content.title_missing_product_terms', $this->codes($findings));
        self::assertNotContains('content.h1_missing_product_terms', $this->codes($findings));
        self::assertNotContains('content.body_missing_product_terms', $this->codes($findings));
    }

    public function test_matching_is_unicode_case_insensitive_for_title_h1_and_body(): void
    {
        if (!function_exists('mb_strtolower')) {
            self::markTestSkipped('Unicode case-insensitive matching requires mb_strtolower.');
        }

        $findings = $this->detect(['Árbol'], $this->page(
            title: 'árbol artesanal',
            h1: 'árbol artesanal',
            bodyTextSummary: 'Este árbol artesanal está disponible.',
        ));

        self::assertNotContains('content.title_missing_product_terms', $this->codes($findings));
        self::assertNotContains('content.h1_missing_product_terms', $this->codes($findings));
        self::assertNotContains('content.body_missing_product_terms', $this->codes($findings));
    }

    public function test_trimmed_duplicate_expected_terms_do_not_cause_false_positives(): void
    {
        $findings = $this->detect(['  Widget  ', 'Widget'], $this->page(
            title: 'Widget page',
            h1: 'Widget',
            bodyTextSummary: 'Widget body copy.',
        ));

        self::assertNotContains('content.title_missing_product_terms', $this->codes($findings));
        self::assertNotContains('content.h1_missing_product_terms', $this->codes($findings));
        self::assertNotContains('content.body_missing_product_terms', $this->codes($findings));
    }

    public function test_partial_alignment_is_accepted(): void
    {
        $findings = $this->detect(['Widget', 'Premium', 'Outdoor'], $this->page(
            title: 'Premium product',
            h1: 'Premium product',
            bodyTextSummary: 'Premium product body copy.',
        ));

        self::assertNotContains('content.title_missing_product_terms', $this->codes($findings));
        self::assertNotContains('content.h1_missing_product_terms', $this->codes($findings));
        self::assertNotContains('content.body_missing_product_terms', $this->codes($findings));
    }

    /**
     * @param array<int, string> $expectedTerms
     * @return array<int, Finding>
     */
    private function detect(array $expectedTerms, ?ParsedPage $parsedPage): array
    {
        return (new ContentAlignmentDetector())->detect($this->context(
            new ProductSubject(expectedUrl: 'https://merchant.test/products/widget', expectedTerms: $expectedTerms),
            $parsedPage,
        ));
    }

    private function context(ProductSubject $product, ?ParsedPage $parsedPage): DetectionContext
    {
        $query = new SearchQuery(text: 'buy widget', provider: 'google');

        return new DetectionContext(
            product: $product,
            query: $query,
            resultSet: new SearchResultSet(query: $query),
            urlMatch: new UrlMatch(matched: false, matchType: 'none', expectedUrl: $product->expectedUrl),
            parsedPage: $parsedPage,
        );
    }

    private function page(
        ?string $title = 'Widget page',
        ?string $metaDescription = 'A complete product description with enough visible characters for this deterministic content check.',
        ?string $h1 = 'Widget page',
        ?string $bodyTextSummary = 'This body summary includes Widget product copy.',
    ): ParsedPage {
        return new ParsedPage(
            url: 'https://merchant.test/products/widget',
            title: $title,
            metaDescription: $metaDescription,
            h1: $h1,
            bodyTextSummary: $bodyTextSummary,
        );
    }

    /**
     * @param array<int, Finding> $findings
     */
    private function findingByCode(array $findings, string $code): ?Finding
    {
        foreach ($findings as $finding) {
            if ($finding->code === $code) {
                return $finding;
            }
        }

        return null;
    }

    /**
     * @param array<int, Finding> $findings
     * @return array<int, string>
     */
    private function codes(array $findings): array
    {
        return array_map(static fn (Finding $finding): string => $finding->code, $findings);
    }
}
