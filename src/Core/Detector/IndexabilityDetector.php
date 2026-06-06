<?php

declare(strict_types=1);

namespace VisibilityDetector\Core\Detector;

use DateTimeImmutable;
use Throwable;
use VisibilityDetector\Core\Page\PageSnapshot;
use VisibilityDetector\Core\Page\ParsedPage;
use VisibilityDetector\Core\Report\Finding;
use VisibilityDetector\Core\Url\UrlNormalizer;

final readonly class IndexabilityDetector implements Detector
{
    private UrlNormalizer $normalizer;

    private DateTimeImmutable $now;

    public function __construct(?UrlNormalizer $normalizer = null, ?DateTimeImmutable $now = null)
    {
        $this->normalizer = $normalizer ?? new UrlNormalizer();
        $this->now = $now ?? new DateTimeImmutable('now');
    }

    /**
     * @return array<int, Finding>
     */
    public function detect(DetectionContext $context): array
    {
        if ($context->pageSnapshot === null && $context->parsedPage === null) {
            return [new Finding(
                code: 'page.indexability_uncertain',
                severity: 'medium',
                confidence: 0.4,
                message: 'No page fetch or parsed-page evidence was supplied, so indexability is uncertain.',
                evidence: $this->baseEvidence($context),
                recommendation: 'Supply PageSnapshot or ParsedPage evidence before treating the page as indexable.',
            )];
        }

        $findings = [];

        if ($context->pageSnapshot !== null) {
            $findings = array_merge($findings, $this->snapshotFindings($context));
        }

        if ($context->parsedPage !== null) {
            $findings = array_merge($findings, $this->parsedPageFindings($context));
        }

        return $findings;
    }

    /**
     * @return array<int, Finding>
     */
    private function snapshotFindings(DetectionContext $context): array
    {
        $snapshot = $context->pageSnapshot;

        if (!$snapshot instanceof PageSnapshot) {
            return [];
        }

        $findings = [];
        $evidence = $this->snapshotEvidence($context, $snapshot);

        if (is_string($snapshot->failureType) && trim($snapshot->failureType) !== '' && $snapshot->failureType !== 'none') {
            $findings[] = new Finding(
                code: 'page.fetch_failed',
                severity: 'high',
                confidence: 0.95,
                message: 'The supplied page snapshot records a fetch failure.',
                evidence: $evidence,
                recommendation: 'Resolve the fetch failure before evaluating page visibility or indexability.',
            );
        }

        if ($snapshot->statusCode !== null && ($snapshot->statusCode < 200 || $snapshot->statusCode > 299)) {
            $findings[] = new Finding(
                code: 'page.http_status_not_ok',
                severity: 'high',
                confidence: 0.95,
                message: 'The product page returned a non-2xx HTTP status in the supplied snapshot.',
                evidence: $evidence,
                recommendation: 'Ensure the product page returns a successful 2xx HTTP status for indexable requests.',
            );
        }

        if ($snapshot->body === null || trim($snapshot->body) === '') {
            $findings[] = new Finding(
                code: 'page.empty_body',
                severity: 'medium',
                confidence: 0.9,
                message: 'The supplied page snapshot has an empty response body.',
                evidence: $evidence,
                recommendation: 'Provide an HTML response body with crawlable product content.',
            );
        }

        if ($snapshot->contentType !== null && !$this->isHtmlContentType($snapshot->contentType)) {
            $findings[] = new Finding(
                code: 'page.non_html_content',
                severity: 'high',
                confidence: 0.9,
                message: 'The supplied page snapshot does not have an HTML content type.',
                evidence: $evidence,
                recommendation: 'Serve the product page as HTML for crawler and search-engine access.',
            );
        }

        return $findings;
    }

    /**
     * @return array<int, Finding>
     */
    private function parsedPageFindings(DetectionContext $context): array
    {
        $parsedPage = $context->parsedPage;

        if (!$parsedPage instanceof ParsedPage) {
            return [];
        }

        $findings = [];
        $evidence = $this->parsedPageEvidence($context, $parsedPage);

        $metaNoindexDirective = $this->firstDirectiveByName($parsedPage->robotsDirectives, 'noindex');
        if ($metaNoindexDirective !== null) {
            $findings[] = new Finding(
                code: 'page.noindex_meta',
                severity: 'high',
                confidence: 0.95,
                message: 'The parsed page contains a meta robots noindex directive.',
                evidence: $evidence + $this->robotsEvidence('meta_robots', $metaNoindexDirective, $parsedPage->robotsDirectives),
                recommendation: 'Remove noindex from the page meta robots directives if the product page should be indexed.',
            );
        }

        $xRobotsNoindexDirective = $this->firstDirectiveByName($parsedPage->xRobotsDirectives, 'noindex');
        if ($xRobotsNoindexDirective !== null) {
            $findings[] = new Finding(
                code: 'page.noindex_x_robots',
                severity: 'high',
                confidence: 0.95,
                message: 'The parsed page contains an X-Robots-Tag noindex directive.',
                evidence: $evidence + $this->robotsEvidence('x_robots_tag', $xRobotsNoindexDirective, $parsedPage->xRobotsDirectives),
                recommendation: 'Remove noindex from X-Robots-Tag headers if the product page should be indexed.',
            );
        }

        foreach ($this->robotsNoneFindings($evidence, 'meta_robots', $parsedPage->robotsDirectives) as $finding) {
            $findings[] = $finding;
        }

        foreach ($this->robotsNoneFindings($evidence, 'x_robots_tag', $parsedPage->xRobotsDirectives) as $finding) {
            $findings[] = $finding;
        }

        foreach ($this->unavailableAfterFindings($evidence, 'meta_robots', $parsedPage->robotsDirectives) as $finding) {
            $findings[] = $finding;
        }

        foreach ($this->unavailableAfterFindings($evidence, 'x_robots_tag', $parsedPage->xRobotsDirectives) as $finding) {
            $findings[] = $finding;
        }

        if ($parsedPage->canonicalUrl !== null && trim($parsedPage->canonicalUrl) !== '' && !$this->canonicalMatchesProduct($context, $parsedPage->canonicalUrl)) {
            $findings[] = new Finding(
                code: 'page.canonical_mismatch',
                severity: 'medium',
                confidence: 0.9,
                message: 'The parsed canonical URL does not match the expected product URL or its acceptable variants.',
                evidence: $evidence + [
                    'canonicalUrl' => $parsedPage->canonicalUrl,
                    'normalizedCanonicalUrl' => $this->normalizer->normalize($parsedPage->canonicalUrl),
                    'normalizedAcceptedUrls' => $this->acceptedCanonicalUrls($context),
                ],
                recommendation: 'Point the canonical URL at the expected product URL or an explicitly acceptable variant.',
            );
        }

        return $findings;
    }

    /**
     * @param array<int, mixed> $directives
     */
    private function firstDirectiveByName(array $directives, string $expectedName): ?string
    {
        foreach ($directives as $directive) {
            if (is_string($directive) && $this->directiveName($directive) === $expectedName) {
                return trim($directive);
            }
        }

        return null;
    }

    private function directiveName(string $directive): string
    {
        $normalized = strtolower(trim($directive));
        $parts = explode(':', $normalized, 2);

        if (count($parts) === 2 && trim($parts[0]) !== '') {
            return trim($parts[1]);
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $baseEvidence
     * @param array<int, mixed> $directives
     * @return array<int, Finding>
     */
    private function robotsNoneFindings(array $baseEvidence, string $source, array $directives): array
    {
        $directive = $this->firstDirectiveByName($directives, 'none');
        if ($directive === null) {
            return [];
        }

        return [new Finding(
            code: 'page.robots_none',
            severity: 'high',
            confidence: 0.95,
            message: 'The parsed page contains a robots none directive, which is equivalent to noindex,nofollow.',
            evidence: $baseEvidence + $this->robotsEvidence($source, $directive, $directives),
            recommendation: 'Remove the robots none directive if the product page should be indexed and followed.',
        )];
    }

    /**
     * @param array<string, mixed> $baseEvidence
     * @param array<int, mixed> $directives
     * @return array<int, Finding>
     */
    private function unavailableAfterFindings(array $baseEvidence, string $source, array $directives): array
    {
        $findings = [];

        foreach ($directives as $directive) {
            if (!is_string($directive)) {
                continue;
            }

            $dateText = $this->unavailableAfterDateText($directive);
            if ($dateText === null) {
                continue;
            }

            $parsedDate = $this->parseHttpDate($dateText);
            $evidence = $baseEvidence + $this->robotsEvidence($source, trim($directive), $directives, $parsedDate);

            if ($parsedDate === null) {
                $findings[] = new Finding(
                    code: 'page.unavailable_after_invalid',
                    severity: 'medium',
                    confidence: 0.9,
                    message: 'The parsed page contains an unavailable_after robots directive with an invalid HTTP-date.',
                    evidence: $evidence,
                    recommendation: 'Replace unavailable_after with a valid HTTP-date or remove it if the product page should remain indexable.',
                );
                continue;
            }

            if ($parsedDate < $this->now) {
                $findings[] = new Finding(
                    code: 'page.unavailable_after_expired',
                    severity: 'high',
                    confidence: 0.95,
                    message: 'The parsed page contains an expired unavailable_after robots directive.',
                    evidence: $evidence,
                    recommendation: 'Remove or update expired unavailable_after directives if the product page should be indexed.',
                );
            }
        }

        return $findings;
    }

    private function unavailableAfterDateText(string $directive): ?string
    {
        $normalized = strtolower(trim($directive));
        $prefix = 'unavailable_after:';

        if (str_starts_with($normalized, $prefix)) {
            return trim(substr(trim($directive), strlen($prefix)));
        }

        $parts = explode(':', $directive, 2);
        if (count($parts) !== 2 || trim($parts[0]) === '') {
            return null;
        }

        $botDirective = trim($parts[1]);
        if (!str_starts_with(strtolower($botDirective), $prefix)) {
            return null;
        }

        return trim(substr($botDirective, strlen($prefix)));
    }

    private function parseHttpDate(string $dateText): ?DateTimeImmutable
    {
        if (trim($dateText) === '') {
            return null;
        }

        $parsedDate = DateTimeImmutable::createFromFormat(DATE_RFC7231, $dateText);
        $parseErrors = DateTimeImmutable::getLastErrors();

        if ($parsedDate instanceof DateTimeImmutable && ($parseErrors === false || ($parseErrors['warning_count'] === 0 && $parseErrors['error_count'] === 0))) {
            return $parsedDate;
        }

        try {
            return new DateTimeImmutable($dateText);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param array<int, mixed> $directives
     * @return array<string, mixed>
     */
    private function robotsEvidence(string $source, string $directive, array $directives, ?DateTimeImmutable $parsedDate = null): array
    {
        $evidence = [
            'source' => $source,
            'directive' => $directive,
            $source === 'meta_robots' ? 'robotsDirectives' : 'xRobotsDirectives' => $directives,
        ];

        if ($parsedDate !== null) {
            $evidence['parsedDate'] = $parsedDate->format(DATE_ATOM);
        }

        if (str_starts_with(strtolower($this->directiveName($directive)), 'unavailable_after')) {
            $evidence['referenceDate'] = $this->now->format(DATE_ATOM);
        }

        return $evidence;
    }

    private function canonicalMatchesProduct(DetectionContext $context, string $canonicalUrl): bool
    {
        return in_array($this->normalizer->normalize($canonicalUrl), $this->acceptedCanonicalUrls($context), true);
    }

    /**
     * @return array<int, string>
     */
    private function acceptedCanonicalUrls(DetectionContext $context): array
    {
        return array_values(array_unique(array_map(
            fn (string $url): string => $this->normalizer->normalize($url),
            array_merge([$context->product->expectedUrl], $context->product->acceptableUrlVariants),
        )));
    }

    private function isHtmlContentType(string $contentType): bool
    {
        $type = strtolower(trim(explode(';', $contentType, 2)[0]));

        return $type === 'text/html' || $type === 'application/xhtml+xml';
    }

    /**
     * @return array<string, mixed>
     */
    private function baseEvidence(DetectionContext $context): array
    {
        return [
            'product' => [
                'expectedUrl' => $context->product->expectedUrl,
                'acceptableUrlVariants' => $context->product->acceptableUrlVariants,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotEvidence(DetectionContext $context, PageSnapshot $snapshot): array
    {
        return $this->baseEvidence($context) + [
            'pageSnapshot' => [
                'requestedUrl' => $snapshot->requestedUrl,
                'finalUrl' => $snapshot->finalUrl,
                'statusCode' => $snapshot->statusCode,
                'contentType' => $snapshot->contentType,
                'failureType' => $snapshot->failureType,
                'warnings' => $snapshot->warnings,
                'bodyLength' => $snapshot->body === null ? null : strlen($snapshot->body),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parsedPageEvidence(DetectionContext $context, ParsedPage $parsedPage): array
    {
        return $this->baseEvidence($context) + [
            'parsedPage' => [
                'url' => $parsedPage->url,
                'canonicalUrl' => $parsedPage->canonicalUrl,
                'parserWarnings' => $parsedPage->parserWarnings,
            ],
        ];
    }
}
