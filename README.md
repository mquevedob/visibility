# visibility-detector

`visibility-detector` is a PHP package for deterministic product visibility analysis.

## v0.1 scope

v0.1 is limited to a core engine skeleton and deterministic analysis of caller-supplied product, query, search-result, and page HTML evidence. It does not include Laravel integration, dashboards, live scraping, crawlers, live search providers, AI answer-engine checks, semantic/vector matching, or external API calls.

## v0.1 usage example

v0.1 analyzes **one product at a time**. The package does not scrape Google, Bing, marketplaces, or any other external provider. Search results and product-page HTML are supplied by the caller, usually from in-memory objects or local fixtures. Given that deterministic evidence, the analyzer produces query visibility findings, deterministic visibility health, and a prioritized summary.

The repository includes a local-only demo under [`examples/`](examples/):

- [`examples/basic-analysis.php`](examples/basic-analysis.php) builds one `ProductSubject`, one expected-visible `SearchQuery`, a `StaticSearchProvider`, a `FixturePageFetcher`, `VisibilityAnalyzer`, and `JsonReportSerializer`.
- [`examples/fixtures/search-results.json`](examples/fixtures/search-results.json) contains static search result evidence where the expected product URL is absent.
- [`examples/fixtures/product-page.html`](examples/fixtures/product-page.html) contains deterministic product-page HTML with technical issues: a `noindex` meta directive, a canonical URL pointing to another page, and no Product/Offer JSON-LD.
- [`examples/sample-report.json`](examples/sample-report.json) is a short deterministic JSON report with `generatedAt` fixed to `2026-01-01T00:00:00+00:00`.

After installing dependencies in your own environment, you can run the example script locally:

```sh
php examples/basic-analysis.php
```

Runtime validation for this repository is owner-managed; the example is intentionally fixture-only and does not perform HTTP calls, browser automation, live scraping, or framework bootstrapping.

### Minimal flow

```php
$product = new ProductSubject(
    expectedUrl: 'https://example.test/products/aurora-trail-shoe',
    name: 'Aurora Trail Shoe',
    brand: 'Acme Outdoor',
    category: 'Trail running shoes',
    expectedTerms: ['Aurora Trail Shoe', 'Acme Outdoor', 'waterproof trail running shoes'],
    commercialPriority: 'critical',
    commercialValue: 'launch_product_high_margin',
);

$query = new SearchQuery(
    text: 'acme waterproof trail running shoes',
    provider: 'static-fixture',
    intent: 'category_product',
    expectedVisibility: true,
    priority: 'critical',
    reason: 'High-value launch product should appear for branded category demand.',
);

$report = $analyzer->analyze($product, [$query]);
$json = (new JsonReportSerializer())->serialize($report, new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
```

The full example wires the supporting objects explicitly:

- `StaticSearchProvider` receives a caller-supplied `SearchResultSet` from the JSON fixture.
- `FixturePageFetcher` receives a `PageSnapshot` whose body comes from the HTML fixture.
- `VisibilityAnalyzer` uses the static provider, fixture fetcher, URL matcher, page parser, detectors, and summary generation.
- `JsonReportSerializer` emits deterministic JSON when passed a fixed timestamp.


### URL evidence policy

Reports use explicit URL roles so search-result matching, fetching, redirects, expected product targets, and parsed canonical declarations are not conflated:

- `matchedUrl` is the URL found in caller-supplied search-result evidence. It is preserved exactly as supplied by the search provider and is not overwritten with a normalized, expected, final, or canonical URL.
- `requestedUrl` is the URL sent to the `PageFetcher` by the analyzer.
- `finalUrl` is the final URL known from fixture or redirect evidence in the `PageSnapshot`.
- `expectedUrl` is the merchant/product URL supplied on `ProductSubject`.
- `canonicalUrl` is the canonical URL declared by the parsed page.

Evaluation is deterministic and one-product scoped:

- Visibility matching compares `expectedUrl` and `acceptableUrlVariants` against the preserved `matchedUrl` from supplied search results.
- Page diagnostics use fetch evidence: `requestedUrl`, `finalUrl`, and page body evidence.
- Canonical diagnostics compare `canonicalUrl` against `expectedUrl` plus `acceptableUrlVariants`.

The top-level `urlEvidence` report section summarizes these roles for the analyzed product. Query-level `urlMatch` evidence preserves each query's `matchedUrl`, and `pageSnapshot` preserves the fetcher's `requestedUrl` and `finalUrl`.

In reports with multiple query visibilities, `urlEvidence.matchedUrls` may include more than one preserved search-result URL. `urlEvidence.requestedUrl`, `urlEvidence.finalUrl`, and `urlEvidence.canonicalUrl` describe the single page snapshot fetched and parsed by the analyzer for the product.

### Reading the JSON output

The main output sections are:

- `queryVisibilities`: one entry per supplied query, including the query context, visible/not-visible/uncertain status, `visibilityHealth`, URL match evidence, query-level findings, and warnings.
- `visibilityHealth`: query-level technical health derived only from deterministic findings. Values are `healthy`, `at_risk`, `blocked`, or `unknown`; this is separate from the visible/not-visible/uncertain result-set status.
- `findings`: diagnostics attached to each query visibility. In the demo these include absence from supplied search results, `noindex`, canonical mismatch, and missing Product/Offer structured data.
- `summary.overallStatus`: the rollup visibility status for the product across supplied queries.
- `summary.overallPriority`: the business priority after combining query priority, product commercial context, and finding severity.
- `summary.topProbableCauses`: the most important likely reasons the product is not visible.
- `summary.topRecommendedActions`: prioritized next actions derived from the findings.
- `evidenceReferences`: structured evidence excerpts copied from the top summary findings, including code, category, affected query, severity, and supporting evidence.

## Documents

- [Project objective](docs/project-objective.md)
- [MVP definition](docs/mvp.md)
- [Architecture](docs/architecture.md)
- [v0.1 roadmap](docs/roadmap/v0.1.md)