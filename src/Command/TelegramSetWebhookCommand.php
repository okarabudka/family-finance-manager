<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:telegram:set-webhook',
    description: 'Registers the Telegram bot webhook URL.',
)]
class TelegramSetWebhookCommand extends Command
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $botToken,
        private readonly string $webhookUrl,
        private readonly string $webhookSecret,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $url = "https://api.telegram.org/bot{$this->botToken}/setWebhook";

        $response = $this->httpClient->request('POST', $url, [
            'json' => [
                'url' => $this->webhookUrl,
                'secret_token' => $this->webhookSecret,
                'allowed_updates' => ['message', 'callback_query'],
            ],
        ]);

        $result = $response->toArray(throw: false);

        if (!empty($result['ok'])) {
            $output->writeln("<info>Webhook registered: {$this->webhookUrl}</info>");

            return Command::SUCCESS;
        }

        $description = \is_string($result['description'] ?? null) ? $result['description'] : 'unknown error';
        $output->writeln("<error>Failed to register webhook: {$description}</error>");

        return Command::FAILURE;
    }
}
