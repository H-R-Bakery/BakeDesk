<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Application\Admin\AdminDashboardProvider;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
#[IsGranted('ROLE_ADMIN')]
final class DashboardController extends AbstractDashboardController
{
    public function __construct(
        #[Autowire('%bakery_brand_name%')]
        private readonly string $bakeryBrandName,
        #[Autowire('%bakedesk_brand_name%')]
        private readonly string $bakedeskBrandName,
        #[Autowire('%bakery_logo_asset%')]
        private readonly string $bakeryLogoAsset,
        private readonly AdminDashboardProvider $dashboardProvider,
    ) {
    }

    public function index(): Response
    {
        return $this->render('admin/dashboard.html.twig', [
            'dashboard' => $this->dashboardProvider->build(),
        ]);
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle(sprintf('%s · %s Administration', $this->bakeryBrandName, $this->bakedeskBrandName))
            ->setFaviconPath($this->bakeryLogoAsset);
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Dashboard', 'fa fa-home');
        yield MenuItem::section('Operations');
        yield MenuItem::linkTo(OrderCrudController::class, 'Orders', 'fa fa-receipt');
        yield MenuItem::linkTo(PrintJobCrudController::class, 'Print Jobs', 'fa fa-list-check');
        yield MenuItem::section('People');
        yield MenuItem::linkTo(UserCrudController::class, 'Users', 'fa fa-user-tie');
        yield MenuItem::linkTo(CustomerCrudController::class, 'Customers', 'fa fa-users');
        yield MenuItem::section('Catalog / Configuration');
        yield MenuItem::linkTo(ProductTypeCrudController::class, 'Product Types', 'fa fa-cookie-bite');
        yield MenuItem::linkTo(UnitCrudController::class, 'Units', 'fa fa-box');
        yield MenuItem::linkTo(PackagingRuleCrudController::class, 'Packaging Rules', 'fa fa-boxes-stacked');
        yield MenuItem::linkTo(PrinterCrudController::class, 'Printers', 'fa fa-print');
        yield MenuItem::section();
        yield MenuItem::linkToRoute('Back to '.$this->bakedeskBrandName, 'fa fa-arrow-left', 'order_new');
    }
}
