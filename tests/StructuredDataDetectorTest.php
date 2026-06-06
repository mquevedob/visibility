<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use VisibilityDetector\Core\Detector\DetectionContext;
use VisibilityDetector\Core\Detector\StructuredDataDetector;
use VisibilityDetector\Core\Page\ParsedPage;
use VisibilityDetector\Core\Product\ProductSubject;
use VisibilityDetector\Core\Report\Finding;
use VisibilityDetector\Core\Search\SearchQuery;
use VisibilityDetector\Core\Search\SearchResultSet;
use VisibilityDetector\Core\Url\UrlMatch;

final class StructuredDataDetectorTest extends TestCase
{
    public function test_missing_parsed_page_emits_no_findings(): void
    {
        self::assertSame([], $this->detector()->detect($this->context(parsedPage: null)));
    }

    public function test_malformed_json_ld_parser_warning_emits_invalid_jsonld_finding(): void
    {
        $findings = $this->detector()->detect($this->context(parsedPage: $this->parsedPage(
            parserWarnings: ['Malformed JSON-LD block at index 0: Syntax error'],
        )));

        self::assertContains('schema.product_invalid_jsonld', $this->codes($findings));
        self::assertSame(['Malformed JSON-LD block at index 0: Syntax error'], $this->findingByCode($findings, 'schema.product_invalid_jsonld')->evidence['parserWarnings']);
    }

    public function test_no_product_schema_emits_product_missing(): void
    {
        $findings = $this->detector()->detect($this->context(parsedPage: $this->parsedPage(
            schemaTypes: ['BreadcrumbList'],
            productSchemaCandidates: [],
            offerSchemaCandidates: [],
        )));

        self::assertContains('schema.product_missing', $this->codes($findings));
    }

    public function test_product_without_offer_emits_offer_missing(): void
    {
        $findings = $this->detector()->detect($this->context(parsedPage: $this->parsedPage(
            productSchemaCandidates: [$this->productCandidate(offers: null)],
            offerSchemaCandidates: [],
        )));

        self::assertContains('schema.offer_missing', $this->codes($findings));
    }

    public function test_offer_without_price_emits_price_missing(): void
    {
        $findings = $this->detector()->detect($this->context(parsedPage: $this->parsedPage(
            offerSchemaCandidates: [['@type' => 'Offer', 'priceCurrency' => 'USD', 'availability' => 'https://schema.org/InStock']],
        )));

        self::assertContains('schema.price_missing', $this->codes($findings));
    }

    public function test_offer_without_price_currency_emits_currency_missing(): void
    {
        $findings = $this->detector()->detect($this->context(parsedPage: $this->parsedPage(
            offerSchemaCandidates: [['@type' => 'Offer', 'price' => '19.99', 'availability' => 'https://schema.org/InStock']],
        )));

        self::assertContains('schema.currency_missing', $this->codes($findings));
    }

    public function test_offer_without_availability_emits_availability_missing(): void
    {
        $findings = $this->detector()->detect($this->context(parsedPage: $this->parsedPage(
            offerSchemaCandidates: [['@type' => 'Offer', 'price' => '19.99', 'priceCurrency' => 'USD']],
        )));

        self::assertContains('schema.availability_missing', $this->codes($findings));
    }

    public function test_product_without_image_emits_image_missing(): void
    {
        $findings = $this->detector()->detect($this->context(parsedPage: $this->parsedPage(
            productSchemaCandidates: [$this->productCandidate(image: null)],
        )));

        self::assertContains('schema.image_missing', $this->codes($findings));
    }

    public function test_product_without_identifier_emits_identifier_missing(): void
    {
        $findings = $this->detector()->detect($this->context(parsedPage: $this->parsedPage(
            productSchemaCandidates: [$this->productCandidate(identifierField: null)],
        )));

        self::assertContains('schema.identifier_missing', $this->codes($findings));
    }

    public function test_complete_product_and_offer_emits_no_findings(): void
    {
        $findings = $this->detector()->detect($this->context(parsedPage: $this->parsedPage()));

        self::assertSame([], $findings);
    }

    public function test_product_with_offers_object_is_recognized(): void
    {
        $findings = $this->detector()->detect($this->context(parsedPage: $this->parsedPage(
            productSchemaCandidates: [$this->productCandidate(offers: $this->offerCandidate())],
            offerSchemaCandidates: [],
        )));

        self::assertNotContains('schema.offer_missing', $this->codes($findings));
        self::assertNotContains('schema.price_missing', $this->codes($findings));
    }

    public function test_product_with_offers_array_is_recognized(): void
    {
        $findings = $this->detector()->detect($this->context(parsedPage: $this->parsedPage(
            productSchemaCandidates: [$this->productCandidate(offers: [$this->offerCandidate()])],
            offerSchemaCandidates: [],
        )));

        self::assertNotContains('schema.offer_missing', $this->codes($findings));
        self::assertNotContains('schema.currency_missing', $this->codes($findings));
    }

    private function detector(): StructuredDataDetector
    {
        return new StructuredDataDetector();
    }

    private function context(?ParsedPage $parsedPage): DetectionContext
    {
        $query = new SearchQuery(text: 'widget', provider: 'static');

        return new DetectionContext(
            product: new ProductSubject(expectedUrl: 'https://merchant.test/products/widget'),
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

    private function parsedPage(
        array $schemaTypes = ['Product', 'Offer'],
        ?array $productSchemaCandidates = null,
        ?array $offerSchemaCandidates = null,
        array $parserWarnings = [],
    ): ParsedPage {
        return new ParsedPage(
            url: 'https://merchant.test/products/widget',
            jsonLdBlocks: [],
            schemaTypes: $schemaTypes,
            productSchemaCandidates: $productSchemaCandidates ?? [$this->productCandidate()],
            offerSchemaCandidates: $offerSchemaCandidates ?? [$this->offerCandidate()],
            parserWarnings: $parserWarnings,
        );
    }

    private function productCandidate(mixed $offers = false, mixed $image = 'https://merchant.test/widget.jpg', ?string $identifierField = 'sku'): array
    {
        $candidate = [
            '@type' => 'Product',
            'name' => 'Widget',
        ];

        if ($image !== null) {
            $candidate['image'] = $image;
        }

        if ($identifierField !== null) {
            $candidate[$identifierField] = 'WIDGET-1';
        }

        if ($offers !== false) {
            $candidate['offers'] = $offers;
        }

        return $candidate;
    }

    private function offerCandidate(): array
    {
        return [
            '@type' => 'Offer',
            'price' => '19.99',
            'priceCurrency' => 'USD',
            'availability' => 'https://schema.org/InStock',
        ];
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
}
