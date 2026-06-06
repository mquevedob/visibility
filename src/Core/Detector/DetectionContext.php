<?php

declare(strict_types=1);

namespace VisibilityDetector\Core\Detector;

use VisibilityDetector\Core\Product\ProductSubject;
use VisibilityDetector\Core\Search\SearchQuery;
use VisibilityDetector\Core\Search\SearchResultSet;
use VisibilityDetector\Core\Url\UrlMatch;

final readonly class DetectionContext
{
    public function __construct(
        public ProductSubject $product,
        public SearchQuery $query,
        public SearchResultSet $resultSet,
        public UrlMatch $urlMatch,
    ) {
    }

    public function toArray(): array
    {
        return [
            'product' => $this->product->toArray(),
            'query' => $this->query->toArray(),
            'resultSet' => $this->resultSet->toArray(),
            'urlMatch' => $this->urlMatch->toArray(),
        ];
    }
}
