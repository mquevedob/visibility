# Architecture

## Architecture principle

The v0.1 architecture is a framework-agnostic PHP core.

It must not depend on Laravel, databases, queues, browser automation, search-engine scraping, vector databases, hosted AI APIs, or ecommerce platform SDKs.

The core receives evidence, analyzes evidence, and returns a structured report.

## High-level pipeline

```text
ProductSubject
  + SearchQuery[]
  + SearchResultSet[]
        |
        v
VisibilityAnalyzer
        |
        +--> UrlMatcher
        |
        +--> PageFetcher
        |
        +--> PageParser
        |
        +--> Detector[]
        |
        v
VisibilityReport
```

## Runtime flow

1. Receive a `ProductSubject`.
2. Receive one or more `SearchQuery` objects.
3. Receive one or more `SearchResultSet` snapshots from a `SearchProvider`.
4. Match the expected product URL against each observed result set.
5. Fetch the expected product page through `PageFetcher`.
6. Parse the fetched page through `PageParser`.
7. Run detectors over the search evidence, URL match evidence, page snapshot, and parsed page.
8. Return a `VisibilityReport`.

## Core namespace layout

Proposed PHP namespace layout:

```text
VisibilityDetector\Core\Product
VisibilityDetector\Core\Search
VisibilityDetector\Core\Url
VisibilityDetector\Core\Page
VisibilityDetector\Core\Detector
VisibilityDetector\Core\Report
VisibilityDetector\Core\Analyzer
VisibilityDetector\Adapters\Static
VisibilityDetector\Adapters\Http
```

For v0.1, only `Core` and deterministic `Static` adapters are required. HTTP fetching can be added behind an interface after fixture tests are stable.

## Core value objects

### ProductSubject

Represents the product whose visibility is being tested.

Fields:

- `id`
- `name`
- `expectedUrl`
- `acceptableUrlVariants`
- `brand`
- `sku`
- `gtin`
- `mpn`
- `category`
- `expectedTerms`
- `locale`
- `metadata`

Rules:

- `expectedUrl` is required.
- `name` is optional.
- Identifiers such as SKU, GTIN, and MPN are optional but useful for future exact-query classification.

### SearchQuery

Represents the query context being tested.

Fields:

- `text`
- `provider`
- `locale`
- `market`
- `device`
- `expectedIntent`
- `metadata`

Examples of `expectedIntent`:

- `exact_product`
- `brand_product`
- `category_product`
- `symptom_or_need`
- `unknown`

For v0.1, intent can be optional metadata. No semantic classifier is required.

### SearchResult

Represents one observed result.

Fields:

- `position`
- `url`
- `title`
- `snippet`
- `resultType`
- `providerPayload`
- `metadata`

### SearchResultSet

Represents the observed result snapshot for one query/provider/device/locale.

Fields:

- `query`
- `provider`
- `locale`
- `market`
- `device`
- `capturedAt`
- `results`
- `warnings`
- `limitations`

### UrlMatch

Represents how a product URL matched or failed to match a search result.

Fields:

- `matched`
- `matchType`
- `expectedUrl`
- `matchedUrl`
- `matchedPosition`
- `matchedResult`
- `evidence`

Possible `matchType` values:

- `none`
- `exact`
- `normalized`
- `acceptable_variant`
- `canonical`

### PageSnapshot

Represents transport-level evidence for the expected product URL.

Fields:

- `requestedUrl`
- `finalUrl`
- `statusCode`
- `headers`
- `body`
- `contentType`
- `redirects`
- `durationMs`
- `failureType`
- `warnings`

Possible `failureType` values:

- `none`
- `dns_not_found`
- `timeout`
- `connection_refused`
- `ssl_error`
- `http_error`
- `invalid_response`
- `unknown`

### ParsedPage

Represents semantic evidence extracted from a product page.

Fields:

- `url`
- `title`
- `metaDescription`
- `canonicalUrl`
- `robotsDirectives`
- `xRobotsDirectives`
- `hreflangLinks`
- `h1`
- `headings`
- `links`
- `jsonLdBlocks`
- `schemaTypes`
- `productSchemaCandidates`
- `offerSchemaCandidates`
- `bodyTextSummary`
- `parserWarnings`

### Finding

Represents one diagnostic result.

Fields:

- `code`
- `severity`
- `confidence`
- `message`
- `evidence`
- `recommendation`

Severity values:

- `critical`
- `high`
- `medium`
- `low`
- `info`

Confidence is numeric from `0.0` to `1.0`.

### QueryVisibility

Represents visibility status for one query.

Fields:

- `query`
- `provider`
- `locale`
- `device`
- `status`
- `urlMatch`
- `matchedResult`
- `findings`
- `warnings`

Status values:

- `visible`
- `not_visible`
- `uncertain`

### VisibilityReport

Top-level output.

Fields:

- `product`
- `queryVisibilities`
- `pageSnapshot`
- `parsedPage`
- `summaryFindings`
- `warnings`
- `generatedAt`

## Core contracts

### SearchProvider

```php
interface SearchProvider
{
    /**
     * @return SearchResultSet[]
     */
    public function search(ProductSubject $product, array $queries): array;
}
```

v0.1 implementation:

- `StaticSearchProvider`

The static provider returns supplied fixtures. It does not call Google, Bing, marketplaces, or any external service.

### UrlMatcher

```php
interface UrlMatcher
{
    public function match(ProductSubject $product, SearchResultSet $resultSet, ?ParsedPage $parsedPage = null): UrlMatch;
}
```

v0.1 implementation:

- `DefaultUrlMatcher`

Responsibilities:

- normalize scheme and host casing;
- strip fragments;
- remove known tracking parameters;
- normalize trailing slashes;
- compare acceptable variants;
- compare canonical URL if available.

### PageFetcher

```php
interface PageFetcher
{
    public function fetch(string $url): PageSnapshot;
}
```

v0.1 implementation:

- `FixturePageFetcher`

Later implementation:

- `Psr18PageFetcher`

### PageParser

```php
interface PageParser
{
    public function parse(PageSnapshot $snapshot): ParsedPage;
}
```

v0.1 implementation:

- `DomPageParser`

The parser should be tolerant. It should return warnings for malformed HTML or malformed JSON-LD instead of throwing for normal page defects.

### Detector

```php
interface Detector
{
    /**
     * @return Finding[]
     */
    public function detect(DetectionContext $context): array;
}
```

`DetectionContext` contains:

- `ProductSubject`
- `SearchQuery`
- `SearchResultSet`
- `UrlMatch`
- `PageSnapshot`
- `ParsedPage`

### ReportSerializer

```php
interface ReportSerializer
{
    public function serialize(VisibilityReport $report): string;
}
```

v0.1 implementation:

- `JsonReportSerializer`

## Initial detector set

### VisibilityResultDetector

Finding codes:

- `product.visible_in_results`
- `product.not_found_in_results`
- `product.visibility_uncertain`

### HttpAvailabilityDetector

Finding codes:

- `page.fetch_failed`
- `page.http_error`
- `page.redirects_elsewhere`
- `page.non_html_response`

### IndexabilityDetector

Finding codes:

For v0.1 compatibility with Phase 8, page/indexability diagnostics use the stable `page.*` namespace rather than `indexability.*`.

- `page.noindex_meta`
- `page.noindex_x_robots`
- `page.robots_none`
- `page.unavailable_after_expired`
- `page.unavailable_after_invalid`

For v0.1, robots.txt support may be fixture-driven or deferred to a separate task, but the detector code should leave room for robots evidence.

### CanonicalDetector

Finding codes:

- `canonical.missing`
- `canonical.invalid`
- `canonical.relative`
- `canonical.multiple_conflicting`
- `canonical.points_to_other_url`
- `canonical.points_to_homepage`

### StructuredDataDetector

Finding codes:

- `schema.product_missing`
- `schema.product_invalid_jsonld`
- `schema.offer_missing`
- `schema.price_missing`
- `schema.currency_missing`
- `schema.availability_missing`
- `schema.image_missing`
- `schema.identifier_missing`

### ContentAlignmentDetector

Finding codes:

- `content.title_missing_product_terms`
- `content.h1_missing_product_terms`
- `content.description_missing`
- `content.description_too_thin`
- `content.body_missing_product_terms`

This detector should remain simple in v0.1. It should compare configured `expectedTerms` against title, H1, description, and body summary. It should not use embeddings or LLMs.

## Design constraints

### Deterministic first

Every v0.1 behavior must be testable with fixtures.

### Evidence over opinions

Every finding must include structured evidence.

Bad:

```text
Your SEO is poor.
```

Good:

```json
{
  "code": "canonical.points_to_other_url",
  "evidence": {
    "expected_url": "https://merchant.example/products/a",
    "canonical_url": "https://merchant.example/category/x"
  }
}
```

### No acquisition coupling

Search result acquisition is not part of v0.1 core.

The core should not know whether results came from:

- manual capture;
- CSV;
- browser extension;
- Google Custom Search API;
- Bing API;
- scraping adapter;
- marketplace API;
- AI answer-engine adapter.

All of those are future adapters.

### No ecommerce-platform coupling

The core should not depend on Laravel, Magento, Adobe Commerce, Shopify, Medusa, WooCommerce, or any database schema.

Platform integrations should translate platform data into `ProductSubject`, `SearchQuery`, and `SearchResultSet`.

## Research influences

The architecture intentionally borrows these source-code-backed patterns from the research phase:

- Seonaut: interface-driven fetching, parser services, issue taxonomy, fixture-like tests.
- Lighthouse: detector metadata, indexability and canonical edge cases, structured evidence.
- LibreCrawl: crawler/helper separation, fetch failure classification, sitemap and link evidence ideas.
- python-seo-analyzer: selector-based extraction, content evidence, duplicate/content-oriented tests.
- site-audit-seo: report field grouping and validation-rule inspiration.
- semantic-fashion-search: future-only seam for product-query matching, hybrid search, locale handling, and adapter boundaries.

## Future extension seams

These are intentionally not implemented in v0.1, but the architecture should not block them:

- live search provider adapters;
- marketplace adapters;
- shopping search adapters;
- AI answer-engine adapters;
- rendered-page fetcher;
- sitemap/robots crawler;
- ProductQueryMatcher;
- semantic/vector query expectation;
- Laravel package wrapper;
- SaaS ingestion and dashboard.
