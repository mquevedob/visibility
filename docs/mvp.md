# MVP Definition

## Product thesis

`visibility-detector` is a deterministic product visibility diagnostic engine.

It helps explain whether an expected product appears in observed external discovery results and, when it does not appear, which technical, content, URL, indexability, canonical, and structured-data issues may explain the lack of visibility.

The MVP is intentionally not a live SERP scraper, marketplace crawler, AI answer-engine tester, dashboard, or SaaS platform. It is the core diagnostic layer that those systems can use later.

## Core question

For a given product and a given query:

> Did the expected product appear in the observed results, and if not, what evidence-backed reasons can we detect from the product URL and page markup?

## MVP input

The MVP receives search evidence from outside the core. In v0.1, this means static fixtures, manually captured results, or adapter-provided snapshots.

Example input shape:

```json
{
  "product": {
    "id": "nike-air-max-women",
    "name": "Nike Air Max Mujer",
    "brand": "Nike",
    "sku": "AIRMAX-WOMEN-001",
    "expected_url": "https://merchant.example/products/nike-air-max-mujer",
    "acceptable_url_variants": [
      "https://www.merchant.example/products/nike-air-max-mujer"
    ],
    "expected_terms": ["nike", "air max", "mujer"]
  },
  "queries": [
    {
      "text": "zapatilla nike air max mujer",
      "provider": "google",
      "locale": "es-PY",
      "device": "desktop"
    }
  ],
  "result_sets": [
    {
      "query": "zapatilla nike air max mujer",
      "provider": "google",
      "locale": "es-PY",
      "device": "desktop",
      "captured_at": "2026-06-05T12:00:00Z",
      "results": [
        {
          "position": 1,
          "url": "https://competitor.example/products/nike-air-max",
          "title": "Nike Air Max Mujer",
          "snippet": "...",
          "result_type": "organic"
        }
      ]
    }
  ]
}
```

## MVP output

The MVP returns a structured visibility report.

Example output shape:

```json
{
  "product": {
    "id": "nike-air-max-women",
    "name": "Nike Air Max Mujer",
    "expected_url": "https://merchant.example/products/nike-air-max-mujer"
  },
  "query_visibilities": [
    {
      "query": "zapatilla nike air max mujer",
      "provider": "google",
      "locale": "es-PY",
      "device": "desktop",
      "status": "not_visible",
      "matched_result": null,
      "findings": [
        {
          "code": "product.not_found_in_results",
          "severity": "high",
          "confidence": 1.0,
          "message": "The expected product URL was not found in the observed result set.",
          "evidence": {
            "expected_url": "https://merchant.example/products/nike-air-max-mujer",
            "result_count": 10,
            "match_types_checked": ["exact", "normalized", "acceptable_variant", "canonical"]
          },
          "recommendation": "Inspect indexability, canonical tags, structured data, and query/content alignment for the expected product page."
        }
      ]
    }
  ],
  "page_evidence": {
    "snapshot": {},
    "parsed_page": {}
  },
  "summary_findings": []
}
```

## In scope for v0.1

### Search result evidence

- Accept static or manually supplied search result snapshots.
- Represent provider, query, locale, device, timestamp, URL, title, snippet, position, result type, and provider warnings.
- Do not collect live external search results inside the core.

### URL matching

- Exact URL match.
- Normalized URL match.
- Acceptable variant match.
- Tracking-parameter-insensitive match.
- Fragment-insensitive match.
- Canonical-aware match after product page parsing.

### Product page fetching

- Fetch the expected product URL.
- Capture requested URL, final URL, status code, headers, redirects, body, content type, timing, and fetch failure type.
- Support a fixture fetcher for deterministic tests.
- Support an HTTP fetcher later, behind the same interface.

### Page parsing

- Extract title.
- Extract meta description.
- Extract canonical URL.
- Extract meta robots directives.
- Extract X-Robots-Tag headers.
- Extract hreflang links.
- Extract H1/H2 headings.
- Extract internal/external links.
- Extract JSON-LD blocks.
- Extract schema.org Product and Offer candidates where possible.
- Return parser warnings instead of failing silently.

### Detectors

Initial detector families:

- Visibility result detector.
- URL matching detector.
- HTTP/page availability detector.
- Indexability detector.
- Canonical detector.
- Structured data detector.
- Basic product content detector.

### Report output

- Return stable finding codes.
- Include severity.
- Include confidence.
- Include structured evidence.
- Include recommendation text.
- Serialize to JSON.

## Out of scope for v0.1

- Live Google scraping.
- Live Bing scraping.
- Marketplace scraping.
- Google Shopping scraping.
- AI answer-engine visibility checks.
- LLM-generated explanations.
- Embeddings.
- Vector search.
- Semantic product-query matching implementation.
- CLIP, FAISS, Google Cloud Retail, MeiliSearch, or other search infrastructure dependencies.
- Browser rendering with Playwright/Puppeteer.
- Full-site crawling.
- Scheduled monitoring.
- SaaS/multi-tenant account management.
- Dashboard UI.
- Laravel integration.
- Medusa integration.
- Competitor analysis.
- Historical ranking charts.

## MVP success criteria

The MVP is successful when it can run deterministic tests for these scenarios:

1. Expected product URL appears exactly in observed results.
2. Expected product URL appears with tracking parameters.
3. Expected product URL appears as an acceptable variant.
4. Expected product URL does not appear.
5. Product page returns HTTP error.
6. Product page redirects to a different product or category.
7. Product page has `noindex` in HTML meta robots.
8. Product page has `noindex` in `X-Robots-Tag`.
9. Product page canonical points to another URL.
10. Product page canonical points to homepage or category page.
11. Product page lacks Product structured data.
12. Product structured data lacks Offer, price, currency, or availability.
13. Product title/H1/body have weak alignment with expected product terms.
14. JSON report includes all relevant evidence and recommendations.

## MVP positioning

The MVP should be described as:

> A deterministic core engine for product visibility diagnostics.

It should not yet be described as:

- an AI visibility platform;
- a SERP monitoring platform;
- a rank tracker;
- an SEO crawler;
- a marketplace intelligence tool;
- an ecommerce observability SaaS.

Those are possible future products built on top of this core.
