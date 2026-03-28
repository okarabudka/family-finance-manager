<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Account;
use App\Entity\Budget;
use App\Entity\Category;
use App\Entity\Family;
use App\Entity\ImportFile;
use App\Entity\SavingsGoal;
use App\Entity\Transaction;
use App\Entity\User;
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
        yield MenuItem::linkToCrud('Users', 'fa fa-users', User::class);
        yield MenuItem::linkToCrud('Families', 'fa fa-house', Family::class);

        yield MenuItem::section('Finance');
        yield MenuItem::linkToCrud('Accounts', 'fa fa-credit-card', Account::class);
        yield MenuItem::linkToCrud('Categories', 'fa fa-tag', Category::class);
        yield MenuItem::linkToCrud('Transactions', 'fa fa-exchange-alt', Transaction::class);
        yield MenuItem::linkToCrud('Budgets', 'fa fa-chart-pie', Budget::class);
        yield MenuItem::linkToCrud('Savings Goals', 'fa fa-piggy-bank', SavingsGoal::class);

        yield MenuItem::section('Import');
        yield MenuItem::linkToCrud('Import Files', 'fa fa-file-import', ImportFile::class);
    }
}
