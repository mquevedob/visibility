<?php

declare(strict_types=1);

namespace VisibilityDetector\Core\Product;

use InvalidArgumentException;

final readonly class ProductSubject
{
    public function __construct(
        public string $expectedUrl,
        public ?string $id = null,
        public ?string $name = null,
        public ?string $brand = null,
        public ?string $sku = null,
        public ?string $category = null,
        public array $acceptableUrlVariants = [],
        public array $expectedTerms = [],
        public ?string $commercialPriority = null,
        public mixed $commercialValue = null,
        public ?float $price = null,
        public ?string $currency = null,
        public ?string $stockStatus = null,
    ) {
        self::requireNonEmpty($expectedUrl, 'expectedUrl');
        self::validateStringArray($acceptableUrlVariants, 'acceptableUrlVariants');
        self::validateStringArray($expectedTerms, 'expectedTerms');
        self::validatePriority($commercialPriority, 'commercialPriority');

        if ($price !== null && $price < 0) {
            throw new InvalidArgumentException('price must be greater than or equal to 0.');
        }
    }

    public static function fromArray(array $data): self
    {
        return new self(
            expectedUrl: self::requiredString($data, 'expectedUrl'),
            id: self::optionalString($data, 'id'),
            name: self::optionalString($data, 'name'),
            brand: self::optionalString($data, 'brand'),
            sku: self::optionalString($data, 'sku'),
            category: self::optionalString($data, 'category'),
            acceptableUrlVariants: $data['acceptableUrlVariants'] ?? [],
            expectedTerms: $data['expectedTerms'] ?? [],
            commercialPriority: self::optionalString($data, 'commercialPriority'),
            commercialValue: $data['commercialValue'] ?? null,
            price: self::optionalFloat($data, 'price'),
            currency: self::optionalString($data, 'currency'),
            stockStatus: self::optionalString($data, 'stockStatus'),
        );
    }

    public function toArray(): array
    {
        return [
            'expectedUrl' => $this->expectedUrl,
            'id' => $this->id,
            'name' => $this->name,
            'brand' => $this->brand,
            'sku' => $this->sku,
            'category' => $this->category,
            'acceptableUrlVariants' => $this->acceptableUrlVariants,
            'expectedTerms' => $this->expectedTerms,
            'commercialPriority' => $this->commercialPriority,
            'commercialValue' => $this->commercialValue,
            'price' => $this->price,
            'currency' => $this->currency,
            'stockStatus' => $this->stockStatus,
        ];
    }

    private static function requiredString(array $data, string $field): string
    {
        if (!array_key_exists($field, $data) || !is_string($data[$field])) {
            throw new InvalidArgumentException($field . ' is required.');
        }

        self::requireNonEmpty($data[$field], $field);

        return $data[$field];
    }

    private static function optionalString(array $data, string $field): ?string
    {
        if (!array_key_exists($field, $data) || $data[$field] === null) {
            return null;
        }

        if (!is_string($data[$field])) {
            throw new InvalidArgumentException($field . ' must be a string.');
        }

        return $data[$field];
    }

    private static function optionalFloat(array $data, string $field): ?float
    {
        if (!array_key_exists($field, $data) || $data[$field] === null) {
            return null;
        }

        if (!is_int($data[$field]) && !is_float($data[$field])) {
            throw new InvalidArgumentException($field . ' must be numeric.');
        }

        return (float) $data[$field];
    }

    private static function requireNonEmpty(string $value, string $field): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException($field . ' must not be empty.');
        }
    }

    private static function validateStringArray(array $values, string $field): void
    {
        foreach ($values as $value) {
            if (!is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException($field . ' must contain only non-empty strings.');
            }
        }
    }

    private static function validatePriority(?string $priority, string $field): void
    {
        if ($priority !== null && !in_array($priority, ['low', 'medium', 'high', 'critical'], true)) {
            throw new InvalidArgumentException($field . ' must be low, medium, high, or critical.');
        }
    }
}
