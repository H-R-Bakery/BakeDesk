<?php

declare(strict_types=1);

namespace App\Application\Admin;

final readonly class AdminDashboardMetric
{
    public function __construct(
        public string $title,
        public int $value,
        public string $description,
        public string $href,
        public string $tone = 'primary',
    ) {
    }
}
