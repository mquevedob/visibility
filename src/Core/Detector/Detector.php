<?php

declare(strict_types=1);

namespace VisibilityDetector\Core\Detector;

interface Detector
{
    /**
     * @return array<int, \VisibilityDetector\Core\Report\Finding>
     */
    public function detect(DetectionContext $context): array;
}
