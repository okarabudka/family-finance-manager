<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Domain\Analytics\AnalyticsService;
use App\Domain\Analytics\CategoryReport;
use App\Domain\Analytics\ChartGenerator;
use App\Repository\UserRepository;
use App\Telegram\BotTranslator;
use App\Telegram\CommandHandlerInterface;
use App\Telegram\TelegramSender;

class StatsCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly UserRepository $userRepo,
        private readonly AnalyticsService $analyticsService,
        private readonly ChartGenerator $chartGenerator,
        private readonly TelegramSender $sender,
        private readonly BotTranslator $translator,
    ) {
    }

    /** @param array<string, mixed> $update */
    public function handle(int $chatId, array $update): void
    {
        $user = $this->userRepo->findByTelegramChatId($chatId);

        if (null === $user || null === $user->getFamily()) {
            $this->sender->sendMessage($chatId, $this->translator->trans('common.register_first'));

            return;
        }

        $family = $user->getFamily();
        $now = new \DateTimeImmutable();

        $report = $this->analyticsService->getMonthlyReport(
            $family,
            new \DateTimeImmutable($now->format('Y-m-01')),
            new \DateTimeImmutable($now->format('Y-m-t')),
        );

        if (empty($report->byCategory)) {
            $this->sender->sendMessage(
                $chatId,
                $this->translator->trans('stats.empty', ['%month%' => $this->translator->monthLabel($now)]),
                parseMode: 'Markdown',
            );

            return;
        }

        // Build data arrays for the pie chart
        $labels = array_map(static fn (CategoryReport $c) => $c->category->getName(), $report->byCategory);
        $amounts = array_map(static fn (CategoryReport $c) => round($c->amount, 2), $report->byCategory);

        $chartPath = $this->chartGenerator->generatePieChart(
            $labels,
            $amounts,
            $this->translator->trans('report.chart_title', ['%month%' => $this->translator->monthLabel($now)]),
        );

        $currency = $family->getCurrency();
        $caption = $this->translator->trans('stats.caption', [
            '%month%' => $this->translator->monthLabel($now),
            '%expenses%' => sprintf('%.2f %s', $report->totalExpenses, $currency),
            '%income%' => sprintf('%.2f %s', $report->totalIncome, $currency),
            '%saved%' => sprintf('%.2f %s', $report->balance, $currency),
        ]);

        $this->sender->sendPhoto($chatId, $chartPath, $caption);

        // Remove the temporary chart file after sending
        if (file_exists($chartPath)) {
            unlink($chartPath);
        }
    }
}
