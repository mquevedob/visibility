<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use VisibilityDetector\Adapters\Static\FixturePageFetcher;
use VisibilityDetector\Core\Page\PageFetcher;
use VisibilityDetector\Core\Page\PageSnapshot;

final class FixturePageFetcherTest extends TestCase
{
    public function test_configured_200_html_response_is_returned(): void
    {
        $url = 'https://merchant.test/products/widget';
        $snapshot = new PageSnapshot(
            requestedUrl: $url,
            finalUrl: $url,
            statusCode: 200,
            headers: ['content-type' => ['text/html; charset=UTF-8']],
            body: '<html><body>Widget</body></html>',
            contentType: 'text/html; charset=UTF-8',
            durationMs: 42,
        );
        $fetcher = new FixturePageFetcher([$snapshot]);

        $result = $fetcher->fetch($url);

        self::assertInstanceOf(PageFetcher::class, $fetcher);
        self::assertSame($snapshot, $result);
        self::assertSame(200, $result->statusCode);
        self::assertSame('<html><body>Widget</body></html>', $result->body);
        self::assertSame('text/html; charset=UTF-8', $result->contentType);
        self::assertSame(42, $result->durationMs);
    }

    public function test_configured_404_response_is_returned_from_array_fixture(): void
    {
        $url = 'https://merchant.test/products/missing-widget';
        $fetcher = new FixturePageFetcher([
            $url => [
                'finalUrl' => $url,
                'statusCode' => 404,
                'headers' => ['content-type' => ['text/html']],
                'body' => '<html><body>Not found</body></html>',
                'contentType' => 'text/html',
                'durationMs' => 15,
                'failureType' => 'http_error',
            ],
        ]);

        $result = $fetcher->fetch($url);

        self::assertSame($url, $result->requestedUrl);
        self::assertSame(404, $result->statusCode);
        self::assertSame('http_error', $result->failureType);
        self::assertSame('<html><body>Not found</body></html>', $result->body);
    }

    public function test_redirect_evidence_is_returned(): void
    {
        $url = 'https://merchant.test/products/widget';
        $finalUrl = 'https://merchant.test/products/widget-new';
        $fetcher = new FixturePageFetcher([
            $url => [
                'finalUrl' => $finalUrl,
                'statusCode' => 200,
                'headers' => ['content-type' => ['text/html']],
                'body' => '<html><body>Widget</body></html>',
                'contentType' => 'text/html',
                'redirects' => [
                    [
                        'from' => $url,
                        'to' => $finalUrl,
                        'statusCode' => 301,
                    ],
                ],
                'durationMs' => 31,
            ],
        ]);

        $result = $fetcher->fetch($url);

        self::assertSame($finalUrl, $result->finalUrl);
        self::assertSame([
            [
                'from' => $url,
                'to' => $finalUrl,
                'statusCode' => 301,
            ],
        ], $result->redirects);
        self::assertSame(31, $result->durationMs);
    }

    public function test_timeout_failure_evidence_is_returned(): void
    {
        $url = 'https://merchant.test/products/slow-widget';
        $fetcher = new FixturePageFetcher([
            $url => [
                'finalUrl' => null,
                'statusCode' => null,
                'headers' => [],
                'body' => null,
                'contentType' => null,
                'redirects' => [],
                'durationMs' => 30000,
                'failureType' => 'timeout',
                'warnings' => ['Fixture simulates a timeout.'],
            ],
        ]);

        $result = $fetcher->fetch($url);

        self::assertSame($url, $result->requestedUrl);
        self::assertNull($result->finalUrl);
        self::assertNull($result->statusCode);
        self::assertNull($result->body);
        self::assertNull($result->contentType);
        self::assertSame('timeout', $result->failureType);
        self::assertSame(['Fixture simulates a timeout.'], $result->warnings);
    }

    public function test_missing_fixture_returns_controlled_failure_snapshot(): void
    {
        $url = 'https://merchant.test/products/unconfigured-widget';
        $result = (new FixturePageFetcher())->fetch($url);

        self::assertSame($url, $result->requestedUrl);
        self::assertNull($result->finalUrl);
        self::assertNull($result->statusCode);
        self::assertSame([], $result->headers);
        self::assertNull($result->body);
        self::assertNull($result->contentType);
        self::assertSame([], $result->redirects);
        self::assertNull($result->durationMs);
        self::assertSame('unknown', $result->failureType);
        self::assertSame(['No page fixture was configured for requested URL: ' . $url], $result->warnings);
    }


    public function test_normalized_fixture_fallback_is_not_used_for_unconfigured_requested_url(): void
    {
        $configuredUrl = 'https://merchant.test/products/widget';
        $requestedUrl = 'https://merchant.test/products/widget?utm_source=newsletter';
        $fetcher = new FixturePageFetcher([
            $configuredUrl => [
                'finalUrl' => $configuredUrl,
                'statusCode' => 200,
                'headers' => ['content-type' => ['text/html']],
                'body' => '<html><body>Widget</body></html>',
                'contentType' => 'text/html',
            ],
        ]);

        $result = $fetcher->fetch($requestedUrl);

        self::assertSame($requestedUrl, $result->requestedUrl);
        self::assertNull($result->finalUrl);
        self::assertNull($result->statusCode);
        self::assertSame('unknown', $result->failureType);
        self::assertSame(['No page fixture was configured for requested URL: ' . $requestedUrl], $result->warnings);
    }

    public function test_non_html_content_type_is_returned(): void
    {
        $url = 'https://merchant.test/products/widget.json';
        $fetcher = new FixturePageFetcher([
            $url => [
                'finalUrl' => $url,
                'statusCode' => 200,
                'headers' => ['content-type' => ['application/json']],
                'body' => '{"name":"Widget"}',
                'contentType' => 'application/json',
            ],
        ]);

        $result = $fetcher->fetch($url);

        self::assertSame(200, $result->statusCode);
        self::assertSame('application/json', $result->contentType);
        self::assertSame('{"name":"Widget"}', $result->body);
    }

    public function test_invalid_fixture_input_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new FixturePageFetcher(['https://merchant.test/products/widget' => 'not a fixture']);
    }

    public function test_fetch_with_empty_url_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new FixturePageFetcher())->fetch('');
    }
}
