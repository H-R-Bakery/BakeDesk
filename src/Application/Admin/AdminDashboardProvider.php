<?php

declare(strict_types=1);

namespace App\Application\Admin;

use App\Application\Order\BakeryClock;
use App\Controller\Admin\PrinterCrudController;
use App\Controller\Admin\PrintJobCrudController;
use App\Controller\Admin\ProductTypeCrudController;
use App\Controller\Admin\UnitCrudController;
use App\Controller\Admin\UserCrudController;
use App\Model\PrintJobStatus;
use App\Repository\OrderRepository;
use App\Repository\PrinterRepository;
use App\Repository\PrintJobRepository;
use App\Repository\ProductTypeRepository;
use App\Repository\UnitRepository;
use App\Repository\UserRepository;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class AdminDashboardProvider
{
    private \DateTimeZone $utcTimezone;

    public function __construct(
        private OrderRepository $orderRepository,
        private PrintJobRepository $printJobRepository,
        private PrinterRepository $printerRepository,
        private UserRepository $userRepository,
        private UnitRepository $unitRepository,
        private ProductTypeRepository $productTypeRepository,
        private BakeryClock $bakeryClock,
        private UrlGeneratorInterface $urlGenerator,
        private AdminUrlGeneratorInterface $adminUrlGenerator,
    ) {
        $this->utcTimezone = new \DateTimeZone('UTC');
    }

    public function build(): AdminDashboard
    {
        $today = $this->bakeryClock->today();
        $tomorrow = $today->modify('+1 day');
        $todayUtc = $today->setTimezone($this->utcTimezone);
        $tomorrowUtc = $tomorrow->setTimezone($this->utcTimezone);

        $defaultLabelPrinters = $this->printerRepository->countDefaultLabelPrinters();
        $usableDefaultLabelPrinters = $this->printerRepository->countUsableDefaultLabelPrinters();
        $availableReportPrinters = $this->printerRepository->countAvailableForReports();
        $availableOrderTakers = $this->userRepository->countAvailableForOrderEntry();
        $activeProductTypes = $this->productTypeRepository->countActive();
        $activeUnits = $this->unitRepository->countActive();
        $printersUrl = $this->adminIndexUrl(PrinterCrudController::class);
        $printJobsUrl = $this->adminIndexUrl(PrintJobCrudController::class);
        $ordersUrl = $this->urlGenerator->generate('order_index');
        $todayOrdersUrl = $this->urlGenerator->generate('order_index', ['pickupDate' => $today->format('Y-m-d')]);
        $failedPrintJobs = $this->printJobRepository->countByStatuses([PrintJobStatus::FAILED]);

        return new AdminDashboard(
            operationMetrics: [
                new AdminDashboardMetric(
                    title: "Today's Pickups",
                    value: $this->orderRepository->countPickupBetween($todayUtc, $tomorrowUtc),
                    description: 'Open and completed orders scheduled today.',
                    href: $todayOrdersUrl,
                ),
                new AdminDashboardMetric(
                    title: 'Upcoming Open',
                    value: $this->orderRepository->countUpcomingOpen($todayUtc),
                    description: 'Open orders from today onward.',
                    href: $ordersUrl,
                ),
                new AdminDashboardMetric(
                    title: 'Unpaid Upcoming',
                    value: $this->orderRepository->countUpcomingUnpaid($todayUtc),
                    description: 'Upcoming open orders still marked unpaid.',
                    href: $ordersUrl,
                ),
                new AdminDashboardMetric(
                    title: 'Active Print Jobs',
                    value: $this->printJobRepository->countByStatuses([
                        PrintJobStatus::QUEUED,
                        PrintJobStatus::PROCESSING,
                        PrintJobStatus::RENDERED,
                        PrintJobStatus::SUBMITTED,
                    ]),
                    description: 'Queued or in-progress print work.',
                    href: $printJobsUrl,
                ),
            ],
            failedPrintJobs: new AdminDashboardMetric(
                title: 'Failed Print Jobs',
                value: $failedPrintJobs,
                description: 0 === $failedPrintJobs ? 'No failures.' : 'Needs attention.',
                href: $printJobsUrl,
                tone: 0 === $failedPrintJobs ? 'success' : 'danger',
            ),
            readinessChecks: [
                $this->defaultLabelPrinterCheck($defaultLabelPrinters, $usableDefaultLabelPrinters, $printersUrl),
                new AdminDashboardReadinessCheck(
                    title: 'Report printer',
                    level: $availableReportPrinters > 0 ? 'ok' : 'warning',
                    message: $availableReportPrinters > 0
                        ? sprintf('%d active report printer%s available.', $availableReportPrinters, 1 === $availableReportPrinters ? '' : 's')
                        : 'No active report printer is configured. Reports remain available as PDFs.',
                    href: $printersUrl,
                    actionLabel: 'Manage printers',
                ),
                new AdminDashboardReadinessCheck(
                    title: 'Order takers',
                    level: $availableOrderTakers > 0 ? 'ok' : 'error',
                    message: $availableOrderTakers > 0
                        ? sprintf('%d active order taker%s available in New Order.', $availableOrderTakers, 1 === $availableOrderTakers ? '' : 's')
                        : 'No active order takers are available in the New Order form.',
                    href: $this->adminIndexUrl(UserCrudController::class),
                    actionLabel: 'Manage users',
                ),
                new AdminDashboardReadinessCheck(
                    title: 'Active Product Types',
                    level: $activeProductTypes > 0 ? 'ok' : 'error',
                    message: $activeProductTypes > 0
                        ? sprintf('%d active product type%s configured.', $activeProductTypes, 1 === $activeProductTypes ? '' : 's')
                        : 'No active Product Types are configured.',
                    href: $this->adminIndexUrl(ProductTypeCrudController::class),
                    actionLabel: 'Manage product types',
                ),
                new AdminDashboardReadinessCheck(
                    title: 'Active Units',
                    level: $activeUnits > 0 ? 'ok' : 'error',
                    message: $activeUnits > 0
                        ? sprintf('%d active unit%s configured.', $activeUnits, 1 === $activeUnits ? '' : 's')
                        : 'No active Units are configured.',
                    href: $this->adminIndexUrl(UnitCrudController::class),
                    actionLabel: 'Manage units',
                ),
                ...$this->conversionChecks(
                    $activeUnits,
                    $this->unitRepository->countActiveWithNonPositiveEachEquivalent(),
                    $this->adminIndexUrl(UnitCrudController::class),
                ),
            ],
        );
    }

    private function defaultLabelPrinterCheck(int $configured, int $usable, string $href): AdminDashboardReadinessCheck
    {
        if (1 === $configured && 1 === $usable) {
            return new AdminDashboardReadinessCheck(
                title: 'Default label printer',
                level: 'ok',
                message: 'One active default printer is available for labels.',
                href: $href,
                actionLabel: 'Manage printers',
            );
        }

        $message = $configured > 1
            ? 'Multiple default label printers are configured; choose exactly one.'
            : 'No active default label printer is configured.';

        return new AdminDashboardReadinessCheck(
            title: 'Default label printer',
            level: 'error',
            message: $message,
            href: $href,
            actionLabel: 'Manage printers',
        );
    }

    /**
     * @return list<AdminDashboardReadinessCheck>
     */
    private function conversionChecks(int $activeUnits, int $invalidUnits, string $href): array
    {
        if (0 === $activeUnits) {
            return [];
        }

        if ($invalidUnits > 0) {
            return [new AdminDashboardReadinessCheck(
                title: 'Production conversions',
                level: 'error',
                message: sprintf('%d active unit%s have a non-positive Each equivalent.', $invalidUnits, 1 === $invalidUnits ? '' : 's'),
                href: $href,
                actionLabel: 'Review units',
            )];
        }

        return [new AdminDashboardReadinessCheck(
            title: 'Production conversions',
            level: 'warning',
            message: sprintf('%d active unit%s configured. Review Each equivalent values before first production use.', $activeUnits, 1 === $activeUnits ? '' : 's'),
            href: $href,
            actionLabel: 'Review units',
        )];
    }

    private function adminIndexUrl(string $controller): string
    {
        return $this->adminUrlGenerator
            ->setController($controller)
            ->setAction(Action::INDEX)
            ->generateUrl();
    }
}
