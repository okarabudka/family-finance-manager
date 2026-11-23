<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
class AdminDashboardController extends AbstractDashboardController
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {}

    public function index(): Response
    {
        return $this->render('admin/dashboard.html.twig');
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle($this->translator->trans('admin.title'))
            ->renderContentMaximized();
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('admin.menu.dashboard', 'fa fa-home');
        yield MenuItem::linkToUrl('admin.menu.back_to_app', 'fa fa-arrow-left', '/dashboard');

        yield MenuItem::section('admin.menu.section_users');
        yield MenuItem::linkTo(UserCrudController::class, 'admin.menu.users', 'fa fa-users');
        yield MenuItem::linkTo(FamilyCrudController::class, 'admin.menu.families', 'fa fa-house');

        yield MenuItem::section('admin.menu.section_finance');
        yield MenuItem::linkTo(AccountCrudController::class, 'admin.menu.accounts', 'fa fa-credit-card');
        yield MenuItem::linkTo(CategoryCrudController::class, 'admin.menu.categories', 'fa fa-tag');
        yield MenuItem::linkTo(TransactionCrudController::class, 'admin.menu.transactions', 'fa fa-exchange-alt');
        yield MenuItem::linkTo(BudgetCrudController::class, 'admin.menu.budgets', 'fa fa-chart-pie');
        yield MenuItem::linkTo(SavingsGoalCrudController::class, 'admin.menu.savings_goals', 'fa fa-piggy-bank');

        yield MenuItem::section('admin.menu.section_import');
        yield MenuItem::linkTo(ImportFileCrudController::class, 'admin.menu.import_files', 'fa fa-file-import');

        yield MenuItem::section('admin.menu.section_notifications');
        yield MenuItem::linkTo(ReminderTemplateCrudController::class, 'admin.menu.reminders', 'fa fa-bell');
    }
}
