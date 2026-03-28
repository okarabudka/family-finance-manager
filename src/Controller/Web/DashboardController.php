<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Domain\Account\AccountService;
use App\Domain\Analytics\AnalyticsService;
use App\Domain\Suggestion\SuggestionService;
use App\Entity\User;
use App\Repository\AccountRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class DashboardController extends AbstractController
{
    #[Route('/dashboard', name: 'dashboard', methods: ['GET'])]
    public function index(
        AccountRepository $accountRepository,
        AccountService $accountService,
        AnalyticsService $analyticsService,
        SuggestionService $suggestionService,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $family = $user->getFamily();

        if ($family === null) {
            return $this->render('dashboard/no_family.html.twig');
        }

        $accounts = $accountRepository->findActiveByFamily($family);

        // Build balance map: account id => balance
        $balances = [];
        foreach ($accounts as $account) {
            $balances[(string) $account->getId()] = $accountService->getBalance($account);
        }

        // Monthly report for current month
        $now = new \DateTimeImmutable();
        $from = new \DateTimeImmutable($now->format('Y-m-01'));
        $to = new \DateTimeImmutable($now->format('Y-m-t'));
        $monthlyReport = $analyticsService->getMonthlyReport($family, $from, $to);

        // 6-month trend data for Chart.js
        $trend = $analyticsService->getMonthlyTrend($family, 6);
        $trendLabels = array_column($trend, 'month');
        $trendExpenses = array_column($trend, 'expenses');
        $trendIncome = array_column($trend, 'income');

        // Chart.js pie chart data
        $categoryLabels = array_map(fn ($c) => $c->category->getName(), $monthlyReport->byCategory);
        $categoryAmounts = array_map(fn ($c) => round($c->amount, 2), $monthlyReport->byCategory);
        $palette = ['#6366F1', '#F59E0B', '#EF4444', '#22C55E', '#3B82F6', '#EC4899', '#14B8A6', '#F97316', '#8B5CF6', '#06B6D4'];
        $categoryColors = [];
        foreach (array_keys($categoryLabels) as $i) {
            $categoryColors[] = $palette[$i % count($palette)];
        }

        // Suggestions
        $suggestions = [];
        try {
            $suggestions = $suggestionService->getSuggestions($family);
        } catch (\Throwable) {
            // Suggestions are non-critical — fail silently
        }

        return $this->render('dashboard/index.html.twig', [
            'accounts'        => $accounts,
            'balances'        => $balances,
            'monthlyReport'   => $monthlyReport,
            'suggestions'     => $suggestions,
            'categoryLabels'  => $categoryLabels,
            'categoryAmounts' => $categoryAmounts,
            'categoryColors'  => $categoryColors,
            'trendLabels'     => $trendLabels,
            'trendExpenses'   => $trendExpenses,
            'trendIncome'     => $trendIncome,
        ]);
    }
}
