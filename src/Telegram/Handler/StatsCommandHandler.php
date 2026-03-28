<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Domain\Analytics\AnalyticsService;
use App\Domain\Analytics\CategoryReport;
use App\Domain\Analytics\ChartGenerator;
use App\Repository\UserRepository;
use App\Telegram\CommandHandlerInterface;
use App\Telegram\TelegramSender;

class StatsCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly UserRepository $userRepo,
        private readonly AnalyticsService $analyticsService,
        private readonly ChartGenerator $chartGenerator,
        private readonly TelegramSender $sender,
    ) {}

    /** @param array<string, mixed> $update */
    public function handle(int $chatId, array $update): void
    {
        $user = $this->userRepo->findByTelegramChatId($chatId);

        if ($user === null || $user->getFamily() === null) {
            $this->sender->sendMessage($chatId, 'Please register first: /start');
            return;
        }

        $family = $user->getFamily();
        $now    = new \DateTimeImmutable();

        $report = $this->analyticsService->getMonthlyReport(
            $family,
            new \DateTimeImmutable($now->format('Y-m-01')),
            new \DateTimeImmutable($now->format('Y-m-t')),
        );

        if (empty($report->byCategory)) {
            $this->sender->sendMessage(
                $chatId,
                sprintf(
                    "*Stats — %s*\n\nNo expense data for this month yet.",
                    $now->format('F Y'),
                ),
                parseMode: 'Markdown',
            );
            return;
        }

        // Build data arrays for the pie chart
        $labels  = array_map(static fn(CategoryReport $c) => $c->category->getName(), $report->byCategory);
        $amounts = array_map(static fn(CategoryReport $c) => round($c->amount, 2), $report->byCategory);

        $chartPath = $this->chartGenerator->generatePieChart(
            $labels,
            $amounts,
            'Expenses ' . $now->format('F Y'),
        );

        $caption = sprintf(
            "*Expenses by category — %s*\n\n"
            . "Expenses: *%.2f EUR*\n"
            . "Income:   *%.2f EUR*\n"
            . "Saved:    *%.2f EUR*",
            $now->format('F Y'),
            $report->totalExpenses,
            $report->totalIncome,
            $report->balance,
        );

        $this->sender->sendPhoto($chatId, $chartPath, $caption);

        // Remove the temporary chart file after sending
        if (file_exists($chartPath)) {
            unlink($chartPath);
        }
    }
}
