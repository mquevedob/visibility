<?php

declare(strict_types=1);

namespace VisibilityDetector\Core\Detector;

use VisibilityDetector\Core\Page\ParsedPage;
use VisibilityDetector\Core\Report\Finding;

final class StructuredDataDetector implements Detector
{
    private const IDENTIFIER_FIELDS = [
        'sku',
        'gtin',
        'gtin8',
        'gtin12',
        'gtin13',
        'gtin14',
        'mpn',
        'productID',
    ];

    /**
     * @return array<int, Finding>
     */
    public function detect(DetectionContext $context): array
    {
        if (!$context->parsedPage instanceof ParsedPage) {
            return [];
        }

        $parsedPage = $context->parsedPage;
        $findings = [];

        $jsonLdWarnings = $this->jsonLdWarnings($parsedPage->parserWarnings);
        if ($jsonLdWarnings !== []) {
            $findings[] = $this->finding(
                parsedPage: $parsedPage,
                code: 'schema.product_invalid_jsonld',
                severity: 'high',
                message: 'The parsed page contains malformed or unusable JSON-LD structured-data evidence.',
                reason: 'jsonld_parser_warning_present',
                recommendation: 'Fix malformed JSON-LD so Product and Offer structured data can be parsed reliably.',
                extraEvidence: ['parserWarnings' => $jsonLdWarnings],
            );
        }

        if ($parsedPage->productSchemaCandidates === []) {
            $findings[] = $this->finding(
                parsedPage: $parsedPage,
                code: 'schema.product_missing',
                severity: 'high',
                message: 'The parsed page does not include Product structured-data evidence.',
                reason: 'product_schema_candidate_missing',
                recommendation: 'Add schema.org Product JSON-LD for this product page.',
            );

            return $findings;
        }

        $offers = $this->offerCandidates($parsedPage);

        if ($offers === []) {
            $findings[] = $this->finding(
                parsedPage: $parsedPage,
                code: 'schema.offer_missing',
                severity: 'high',
                message: 'Product structured data is present, but no Offer structured-data evidence was found.',
                reason: 'offer_schema_candidate_missing',
                recommendation: 'Add Offer structured data with price, currency, and availability details.',
                extraEvidence: ['candidateExcerpt' => $this->candidateExcerpt($parsedPage->productSchemaCandidates[0] ?? null)],
            );
        } else {
            $missingOfferFields = [
                'price' => ['schema.price_missing', 'high', 'offer_price_missing', 'Add a price value to Offer structured data.'],
                'priceCurrency' => ['schema.currency_missing', 'high', 'offer_currency_missing', 'Add a priceCurrency value to Offer structured data.'],
                'availability' => ['schema.availability_missing', 'medium', 'offer_availability_missing', 'Add an availability value to Offer structured data.'],
            ];

            foreach ($missingOfferFields as $field => [$code, $severity, $reason, $recommendation]) {
                if (!$this->anyCandidateHasField($offers, $field)) {
                    $findings[] = $this->finding(
                        parsedPage: $parsedPage,
                        code: $code,
                        severity: $severity,
                        message: 'Offer structured data is missing ' . $field . ' evidence.',
                        reason: $reason,
                        recommendation: $recommendation,
                        extraEvidence: ['candidateExcerpt' => $this->candidateExcerpt($offers[0] ?? null)],
                    );
                }
            }
        }

        if (!$this->anyCandidateHasField($parsedPage->productSchemaCandidates, 'image')) {
            $findings[] = $this->finding(
                parsedPage: $parsedPage,
                code: 'schema.image_missing',
                severity: 'medium',
                message: 'Product structured data is missing image evidence.',
                reason: 'product_image_missing',
                recommendation: 'Add an image string or image array to Product structured data.',
                extraEvidence: ['candidateExcerpt' => $this->candidateExcerpt($parsedPage->productSchemaCandidates[0] ?? null)],
            );
        }

        if (!$this->anyCandidateHasAnyField($parsedPage->productSchemaCandidates, self::IDENTIFIER_FIELDS)) {
            $findings[] = $this->finding(
                parsedPage: $parsedPage,
                code: 'schema.identifier_missing',
                severity: 'low',
                message: 'Product structured data is missing common product identifier evidence.',
                reason: 'product_identifier_missing',
                recommendation: 'Add sku, gtin, mpn, or productID to Product structured data when available.',
                extraEvidence: ['candidateExcerpt' => $this->candidateExcerpt($parsedPage->productSchemaCandidates[0] ?? null)],
            );
        }

        return $findings;
    }

    /**
     * @param array<int, mixed> $warnings
     * @return array<int, string>
     */
    private function jsonLdWarnings(array $warnings): array
    {
        return array_values(array_filter($warnings, static function (mixed $warning): bool {
            if (!is_string($warning)) {
                return false;
            }

            $normalized = strtolower($warning);

            return str_contains($normalized, 'json-ld')
                || str_contains($normalized, 'jsonld')
                || (str_contains($normalized, 'json') && str_contains($normalized, 'ld'));
        }));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function offerCandidates(ParsedPage $parsedPage): array
    {
        $offers = array_values(array_filter($parsedPage->offerSchemaCandidates, static fn (mixed $candidate): bool => is_array($candidate)));

        foreach ($parsedPage->productSchemaCandidates as $productCandidate) {
            if (!is_array($productCandidate) || !array_key_exists('offers', $productCandidate)) {
                continue;
            }

            foreach ($this->normalizeOfferValue($productCandidate['offers']) as $offer) {
                $offers[] = $offer;
            }
        }

        return $offers;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeOfferValue(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        if ($this->isList($value)) {
            return array_values(array_filter($value, static fn (mixed $item): bool => is_array($item)));
        }

        return [$value];
    }

    /**
     * @param array<int, mixed> $candidates
     */
    private function anyCandidateHasField(array $candidates, string $field): bool
    {
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            if (array_key_exists($field, $candidate) && $this->hasValue($candidate[$field])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, mixed> $candidates
     * @param array<int, string> $fields
     */
    private function anyCandidateHasAnyField(array $candidates, array $fields): bool
    {
        foreach ($fields as $field) {
            if ($this->anyCandidateHasField($candidates, $field)) {
                return true;
            }
        }

        return false;
    }

    private function hasValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        if (is_array($value)) {
            return $value !== [];
        }

        return true;
    }

    /**
     * @param array<string, mixed> $extraEvidence
     */
    private function finding(ParsedPage $parsedPage, string $code, string $severity, string $message, string $reason, string $recommendation, array $extraEvidence = []): Finding
    {
        return new Finding(
            code: $code,
            severity: $severity,
            confidence: 0.9,
            message: $message,
            evidence: [
                'schemaTypes' => $parsedPage->schemaTypes,
                'jsonLdBlockCount' => count($parsedPage->jsonLdBlocks),
                'productSchemaCandidateCount' => count($parsedPage->productSchemaCandidates),
                'offerSchemaCandidateCount' => count($parsedPage->offerSchemaCandidates),
                'reason' => $reason,
            ] + $extraEvidence,
            recommendation: $recommendation,
        );
    }

    private function candidateExcerpt(mixed $candidate): mixed
    {
        if (!is_array($candidate)) {
            return null;
        }

        return array_slice($candidate, 0, 8, true);
    }

    /**
     * @param array<mixed> $value
     */
    private function isList(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }
}
