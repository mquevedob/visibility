<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use VisibilityDetector\Core\Product\ProductSubject;
use VisibilityDetector\Core\Search\SearchQuery;
use VisibilityDetector\Core\Search\SearchResult;
use VisibilityDetector\Core\Search\SearchResultSet;
use VisibilityDetector\Core\Url\DefaultUrlMatcher;
use VisibilityDetector\Core\Url\UrlNormalizer;

final class UrlMatcherTest extends TestCase
{
    private DefaultUrlMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new DefaultUrlMatcher();
    }

    public function test_exact_url_match(): void
    {
        $match = $this->matcher->match(
            new ProductSubject(expectedUrl: 'https://merchant.test/products/widget'),
            $this->resultSet([
                new SearchResult(position: 1, url: 'https://merchant.test/products/widget'),
            ]),
        );

        self::assertTrue($match->matched);
        self::assertSame('exact', $match->matchType);
        self::assertSame('https://merchant.test/products/widget', $match->expectedUrl);
        self::assertSame('https://merchant.test/products/widget', $match->matchedUrl);
        self::assertSame('https://merchant.test/products/widget', $match->evidence['matchedUrl']);
    }

    public function test_normalized_scheme_and_host_match(): void
    {
        $match = $this->matcher->match(
            new ProductSubject(expectedUrl: 'HTTPS://MERCHANT.TEST/products/widget'),
            $this->resultSet([
                new SearchResult(position: 1, url: 'https://merchant.test/products/widget'),
            ]),
        );

        self::assertTrue($match->matched);
        self::assertSame('normalized', $match->matchType);
    }

    public function test_trailing_slash_equivalence(): void
    {
        $match = $this->matcher->match(
            new ProductSubject(expectedUrl: 'https://merchant.test/products/widget/'),
            $this->resultSet([
                new SearchResult(position: 1, url: 'https://merchant.test/products/widget'),
            ]),
        );

        self::assertTrue($match->matched);
        self::assertSame('normalized', $match->matchType);
    }

    public function test_fragment_insensitive_match(): void
    {
        $match = $this->matcher->match(
            new ProductSubject(expectedUrl: 'https://merchant.test/products/widget#details'),
            $this->resultSet([
                new SearchResult(position: 1, url: 'https://merchant.test/products/widget'),
            ]),
        );

        self::assertTrue($match->matched);
        self::assertSame('normalized', $match->matchType);
    }

    public function test_tracking_parameter_insensitive_match(): void
    {
        $match = $this->matcher->match(
            new ProductSubject(expectedUrl: 'https://merchant.test/products/widget?color=red&utm_source=newsletter&gclid=abc'),
            $this->resultSet([
                new SearchResult(position: 1, url: 'https://merchant.test/products/widget?color=red&fbclid=xyz'),
            ]),
        );

        self::assertTrue($match->matched);
        self::assertSame('normalized', $match->matchType);
        self::assertSame('https://merchant.test/products/widget?color=red&fbclid=xyz', $match->matchedUrl);
        self::assertSame('https://merchant.test/products/widget?color=red&fbclid=xyz', $match->evidence['matchedUrl']);
        self::assertSame(
            'https://merchant.test/products/widget?color=red',
            $match->evidence['normalizedExpectedUrl'],
        );
    }

    public function test_same_meaningful_query_parameters_in_different_order_match_after_normalization(): void
    {
        $match = $this->matcher->match(
            new ProductSubject(expectedUrl: 'https://merchant.test/products/widget?color=red&size=m'),
            $this->resultSet([
                new SearchResult(position: 1, url: 'https://merchant.test/products/widget?size=m&color=red'),
            ]),
        );

        self::assertTrue($match->matched);
        self::assertSame('normalized', $match->matchType);
        self::assertSame(
            'https://merchant.test/products/widget?color=red&size=m',
            $match->evidence['normalizedExpectedUrl'],
        );
        self::assertSame(
            'https://merchant.test/products/widget?color=red&size=m',
            $match->evidence['normalizedMatchedUrl'],
        );
    }

    public function test_acceptable_variant_match(): void
    {
        $match = $this->matcher->match(
            new ProductSubject(
                expectedUrl: 'https://merchant.test/products/widget',
                acceptableUrlVariants: ['https://merchant.test/widget?sku=123&utm_campaign=spring'],
            ),
            $this->resultSet([
                new SearchResult(position: 1, url: 'https://merchant.test/widget?sku=123'),
            ]),
        );

        self::assertTrue($match->matched);
        self::assertSame('acceptable_variant', $match->matchType);
        self::assertSame('https://merchant.test/widget?sku=123', $match->matchedUrl);
        self::assertSame('https://merchant.test/widget?sku=123', $match->evidence['matchedUrl']);
        self::assertSame('https://merchant.test/widget?sku=123&utm_campaign=spring', $match->evidence['acceptableUrlVariant']);
    }

    public function test_different_meaningful_query_parameter_values_do_not_match(): void
    {
        $match = $this->matcher->match(
            new ProductSubject(expectedUrl: 'https://merchant.test/products/widget?color=red'),
            $this->resultSet([
                new SearchResult(position: 1, url: 'https://merchant.test/products/widget?color=blue'),
            ]),
        );

        self::assertFalse($match->matched);
        self::assertSame('none', $match->matchType);
    }

    public function test_duplicate_meaningful_parameters_normalize_deterministically(): void
    {
        $normalizer = new UrlNormalizer();

        self::assertSame(
            'https://merchant.test/products/widget?color=blue&color=red&size=m',
            $normalizer->normalize('https://merchant.test/products/widget?size=m&color=red&utm_medium=cpc&color=blue'),
        );
        self::assertSame(
            'https://merchant.test/products/widget?color=blue&color=red&size=m',
            $normalizer->normalize('https://merchant.test/products/widget?color=blue&size=m&color=red&fbclid=abc'),
        );
    }

    public function test_no_match(): void
    {
        $match = $this->matcher->match(
            new ProductSubject(expectedUrl: 'https://merchant.test/products/widget'),
            $this->resultSet([
                new SearchResult(position: 1, url: 'https://competitor.test/products/widget'),
            ]),
        );

        self::assertFalse($match->matched);
        self::assertSame('none', $match->matchType);
        self::assertNull($match->matchedUrl);
        self::assertNull($match->matchedPosition);
        self::assertNull($match->matchedResult);
        self::assertSame(1, $match->evidence['resultCount']);
    }

    public function test_result_position_is_preserved_in_url_match(): void
    {
        $match = $this->matcher->match(
            new ProductSubject(expectedUrl: 'https://merchant.test/products/widget'),
            $this->resultSet([
                new SearchResult(position: 1, url: 'https://competitor.test/products/widget'),
                new SearchResult(position: 7, url: 'https://merchant.test/products/widget'),
            ]),
        );

        self::assertSame(7, $match->matchedPosition);
    }

    public function test_matched_search_result_is_preserved_in_url_match(): void
    {
        $matchedResult = new SearchResult(
            position: 3,
            url: 'https://merchant.test/products/widget',
            title: 'Widget',
            snippet: 'Official product page.',
        );

        $match = $this->matcher->match(
            new ProductSubject(expectedUrl: 'https://merchant.test/products/widget'),
            $this->resultSet([$matchedResult]),
        );

        self::assertSame($matchedResult, $match->matchedResult);
    }

    /**
     * @param array<int, SearchResult> $results
     */
    private function resultSet(array $results): SearchResultSet
    {
        return new SearchResultSet(
            query: new SearchQuery(text: 'buy widget', provider: 'static'),
            results: $results,
        );
    }
}
