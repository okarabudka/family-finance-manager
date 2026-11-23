<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
class AdminDashboardController extends AbstractDashboardController
{
    public function index(): Response
    {
        return $this->render('admin/dashboard.html.twig');
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('Family Finance — Admin')
            ->renderContentMaximized();
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Dashboard', 'fa fa-home');
        yield MenuItem::linkToUrl('← Back to App', 'fa fa-arrow-left', '/dashboard');

        yield MenuItem::section('Users & Families');
        yield MenuItem::linkTo(UserCrudController::class, 'Users', 'fa fa-users');
        yield MenuItem::linkTo(FamilyCrudController::class, 'Families', 'fa fa-house');

        yield MenuItem::section('Finance');
        yield MenuItem::linkTo(AccountCrudController::class, 'Accounts', 'fa fa-credit-card');
        yield MenuItem::linkTo(CategoryCrudController::class, 'Categories', 'fa fa-tag');
        yield MenuItem::linkTo(TransactionCrudController::class, 'Transactions', 'fa fa-exchange-alt');
        yield MenuItem::linkTo(BudgetCrudController::class, 'Budgets', 'fa fa-chart-pie');
        yield MenuItem::linkTo(SavingsGoalCrudController::class, 'Savings Goals', 'fa fa-piggy-bank');

        yield MenuItem::section('Import');
        yield MenuItem::linkTo(ImportFileCrudController::class, 'Import Files', 'fa fa-file-import');

        yield MenuItem::section('Notifications');
        yield MenuItem::linkTo(ReminderTemplateCrudController::class, 'Reminders', 'fa fa-bell');
    }
}
