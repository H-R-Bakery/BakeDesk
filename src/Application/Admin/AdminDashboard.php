<?php

declare(strict_types=1);

namespace App\Application\Admin;

final readonly class AdminDashboard
{
    /**
     * @param list<AdminDashboardMetric>         $operationMetrics
     * @param list<AdminDashboardReadinessCheck> $readinessChecks
     */
    public function __construct(
        public array $operationMetrics,
        public AdminDashboardMetric $failedPrintJobs,
        public array $readinessChecks,
    ) {
    }
}
