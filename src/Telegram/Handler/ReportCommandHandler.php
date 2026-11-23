<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Domain\Analytics\AnalyticsService;
use App\Domain\Analytics\CategoryReport;
use App\Domain\Analytics\ChartGenerator;
use App\Entity\Family;
use App\Repository\UserRepository;
use App\Telegram\CommandHandlerInterface;
use App\Telegram\TelegramSender;
use App\Telegram\BotTranslator;

class ReportCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly UserRepository $userRepo,
        private readonly AnalyticsService $analyticsService,
        private readonly ChartGenerator $chartGenerator,
        private readonly TelegramSender $sender,
        private readonly BotTranslator $translator,
    ) {}

    /** @param array<string, mixed> $update */
    public function handle(int $chatId, array $update): void
    {
        $user = $this->userRepo->findByTelegramChatId($chatId);
        if ($user === null || $user->getFamily() === null) {
            $this->sender->sendMessage($chatId, $this->translator->trans('common.register_first'));

            return;
        }

        $now  = new \DateTimeImmutable();
        $from = $now->modify('first day of this month')->setTime(0, 0);
        $to   = $now->modify('last day of this month')->setTime(23, 59, 59);

        // getMonthlyReport() returns MonthlyReport object — access via object properties, NOT array keys
        $report = $this->analyticsService->getMonthlyReport($user->getFamily(), $from, $to);

        $monthLabel = $this->translator->monthLabel($now);

        $totalIncome    = number_format($report->totalIncome, 2, '.', ' ');
        $totalExpenses  = number_format($report->totalExpenses, 2, '.', ' ');
        $balance        = number_format($report->balance, 2, '.', ' ');

        $currency = $user->getFamily()->getCurrency();

        $text = $this->translator->trans('report.summary', [
            '%month%'    => $monthLabel,
            '%income%'   => "{$totalIncome} {$currency}",
            '%expenses%' => "{$totalExpenses} {$currency}",
            '%balance%'  => "{$balance} {$currency}",
        ])."\n";

        if (count($report->byCategory) > 0) {
            $text .= "\n".$this->translator->trans('report.by_category')."\n";

            foreach ($report->byCategory as $item) {
                $pct    = round($item->percent);
                $amount = number_format($item->amount, 2, '.', ' ');
                $text  .= "- {$item->category->getName()}: {$amount} {$currency} ({$pct}%)\n";
            }
        }

        $keyboard = [
            'inline_keyboard' => [[
                ['text' => $this->translator->trans('report.button_chart'), 'callback_data' => 'report:chart:month'],
                ['text' => $this->translator->trans('report.button_details'), 'callback_data' => 'report:details:month'],
            ]],
        ];

        $this->sender->sendMessage($chatId, $text, $keyboard, 'Markdown');
    }

    /**
     * Handles report:chart:month and report:details:month callbacks.
     */
    public function handleCallback(int $chatId, string $callbackData): void
    {
        $user = $this->userRepo->findByTelegramChatId($chatId);
        if ($user === null || $user->getFamily() === null) {
            $this->sender->sendMessage($chatId, $this->translator->trans('common.register_first'));

            return;
        }

        $parts = explode(':', $callbackData, 3);

        match ($parts[1] ?? '') {
            'chart'   => $this->sendChart($chatId, $user->getFamily()),
            'details' => $this->sendDetails($chatId, $user->getFamily()),
            default   => null,
        };
    }

    private function sendChart(int $chatId, Family $family): void
    {
        $now    = new \DateTimeImmutable();
        $from   = new \DateTimeImmutable($now->format('Y-m-01'));
        $to     = new \DateTimeImmutable($now->format('Y-m-t'));
        $report = $this->analyticsService->getMonthlyReport($family, $from, $to);

        if (empty($report->byCategory)) {
            $this->sender->sendMessage($chatId, $this->translator->trans('common.no_expense_data'));

            return;
        }

        $labels    = array_map(static fn(CategoryReport $c) => $c->category->getName(), $report->byCategory);
        $amounts   = array_map(static fn(CategoryReport $c) => round($c->amount, 2), $report->byCategory);
        $chartPath = $this->chartGenerator->generatePieChart($labels, $amounts, $this->translator->trans('report.chart_title', ['%month%' => $this->translator->monthLabel($now)]));

        $this->sender->sendPhoto($chatId, $chartPath, $this->translator->trans('report.chart_caption', ['%month%' => $this->translator->monthLabel($now)]));

        if (file_exists($chartPath)) {
            unlink($chartPath);
        }
    }

    private function sendDetails(int $chatId, Family $family): void
    {
        $now      = new \DateTimeImmutable();
        $from     = new \DateTimeImmutable($now->format('Y-m-01'));
        $to       = new \DateTimeImmutable($now->format('Y-m-t'));
        $report   = $this->analyticsService->getMonthlyReport($family, $from, $to);
        $currency = $family->getCurrency();

        if (empty($report->byCategory)) {
            $this->sender->sendMessage($chatId, $this->translator->trans('common.no_expense_data'));

            return;
        }

        $text = $this->translator->trans('report.details_title', ['%month%' => $this->translator->monthLabel($now)])."\n\n";
        foreach ($report->byCategory as $item) {
            $amount = number_format($item->amount, 2, '.', ' ');
            $pct    = round($item->percent);
            $budget = $item->budgetAmount !== null
                ? ' / '.$this->translator->trans('report.planned', ['%amount%' => number_format($item->budgetAmount, 2, '.', ' ').' '.$currency])
                : '';
            $text .= "*{$item->category->getName()}*: {$amount} {$currency} ({$pct}%){$budget}\n";
        }

        $this->sender->sendMessage($chatId, $text, parseMode: 'Markdown');
    }
}
