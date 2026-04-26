<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Domain\Analytics\AnalyticsService;
use App\Message\GenerateReportMessage;
use App\Repository\FamilyRepository;
use App\Telegram\TelegramSender;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class GenerateReportMessageHandler
{
    public function __construct(
        private readonly FamilyRepository $familyRepo,
        private readonly AnalyticsService $analyticsService,
        private readonly TelegramSender $sender,
    ) {}

    public function __invoke(GenerateReportMessage $message): void
    {
        $family = $this->familyRepo->find($message->familyId);

        if ($family === null) {
            return;
        }

        $from = new \DateTimeImmutable(
            sprintf('%04d-%02d-01', $message->year, $message->month)
        );
        $to = $from->modify('last day of this month')->setTime(23, 59, 59);

        $report     = $this->analyticsService->getMonthlyReport($family, $from, $to);
        $monthLabel = $from->format('F Y');
        $currency   = $family->getCurrency();

        $totalIncome   = number_format($report->totalIncome, 2, '.', ' ');
        $totalExpenses = number_format($report->totalExpenses, 2, '.', ' ');
        $balance       = number_format($report->balance, 2, '.', ' ');

        $text  = "*Report for {$monthLabel}*\n\n";
        $text .= "Income:   *{$totalIncome} {$currency}*\n";
        $text .= "Expenses: *{$totalExpenses} {$currency}*\n";
        $text .= "Balance:  *{$balance} {$currency}*\n";

        if (count($report->byCategory) > 0) {
            $text .= "\n*By category:*\n";

            foreach ($report->byCategory as $item) {
                $pct    = round($item->percent);
                $amount = number_format($item->amount, 2, '.', ' ');
                $text  .= "- {$item->category->getName()}: {$amount} {$currency} ({$pct}%)\n";
            }
        }

        foreach ($family->getMembers() as $member) {
            $chatId = $member->getTelegramChatId();
            if ($chatId !== null) {
                $this->sender->sendMessage($chatId, $text, parseMode: 'Markdown');
            }
        }
    }
}
