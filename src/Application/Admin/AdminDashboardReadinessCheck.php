<?php

declare(strict_types=1);

namespace App\Application\Admin;

final readonly class AdminDashboardReadinessCheck
{
    public function __construct(
        public string $title,
        public string $level,
        public string $message,
        public string $href,
        public string $actionLabel,
    ) {
    }
}
