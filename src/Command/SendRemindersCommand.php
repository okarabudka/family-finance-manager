<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\SendReminderMessage;
use App\Repository\ReminderTemplateRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:reminders:send',
    description: 'Check and dispatch due reminder notifications. Run every hour via cron.',
)]
class SendRemindersCommand extends Command
{
    public function __construct(
        private readonly ReminderTemplateRepository $reminderTemplateRepository,
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = new \DateTimeImmutable();
        $templates = $this->reminderTemplateRepository->findAllActive();
        $sent = 0;

        foreach ($templates as $template) {
            if (!$template->isDue($now)) {
                continue;
            }

            $this->messageBus->dispatch(new SendReminderMessage((string) $template->getId()));
            $output->writeln(sprintf('Dispatched: %s', $template->getName()));
            ++$sent;
        }

        $output->writeln(sprintf('Done. Dispatched %d reminder(s).', $sent));

        return Command::SUCCESS;
    }
}
