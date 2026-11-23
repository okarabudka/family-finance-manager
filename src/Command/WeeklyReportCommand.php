<?php

declare(strict_types=1);

namespace App\Command;

use App\Domain\Suggestion\SuggestionService;
use App\Repository\FamilyRepository;
use App\Telegram\BotTranslator;
use App\Telegram\TelegramSender;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:report:weekly',
    description: 'Sends a weekly financial summary with suggestions to all family members via Telegram.',
)]
class WeeklyReportCommand extends Command
{
    public function __construct(
        private readonly FamilyRepository $familyRepo,
        private readonly SuggestionService $suggestionService,
        private readonly TelegramSender $sender,
        private readonly LoggerInterface $logger,
        private readonly BotTranslator $translator,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $families = $this->familyRepo->findAll();

        foreach ($families as $family) {
            try {
                $suggestions = $this->suggestionService->getSuggestions($family);
            } catch (\Throwable $e) {
                $this->logger->error('WeeklyReportCommand: getSuggestions failed', [
                    'family' => (string) $family->getId(),
                    'error' => $e->getMessage(),
                ]);
                $output->writeln(sprintf('<error>Skipped family %s: %s</error>', $family->getId(), $e->getMessage()));
                continue;
            }

            foreach ($family->getMembers() as $user) {
                $chatId = $user->getTelegramChatId();
                if (null === $chatId) {
                    continue;
                }

                if (empty($suggestions)) {
                    $this->sender->sendMessage(
                        $chatId,
                        $this->translator->trans('weekly.all_good'),
                    );
                } else {
                    $text = $this->translator->trans('weekly.title')."\n\n";
                    foreach (array_slice($suggestions, 0, 3) as $suggestion) {
                        $text .= "- *{$suggestion->title}*: {$suggestion->message}\n\n";
                    }
                    $this->sender->sendMessage($chatId, $text, parseMode: 'Markdown');
                }

                $output->writeln(sprintf(
                    'Sent weekly report to chat %d (%d suggestion(s)).',
                    $chatId,
                    count($suggestions),
                ));
            }
        }

        return Command::SUCCESS;
    }
}
