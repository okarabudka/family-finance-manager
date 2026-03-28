<?php

declare(strict_types=1);

namespace App\Telegram\Conversation;

use App\Domain\Import\FormatDetector;
use App\Domain\Import\UnsupportedFormatException;
use App\Entity\ImportFile;
use App\Message\ProcessImportMessage;
use App\Repository\AccountRepository;
use App\Repository\UserRepository;
use App\Telegram\ConversationInterface;
use App\Telegram\ConversationManager;
use App\Telegram\TelegramFileDownloader;
use App\Telegram\TelegramSender;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

class ImportConversation implements ConversationInterface
{
    public function __construct(
        private readonly TelegramSender $sender,
        private readonly ConversationManager $conversationManager,
        private readonly TelegramFileDownloader $telegramFileDownloader,
        private readonly FormatDetector $formatDetector,
        private readonly UserRepository $userRepo,
        private readonly AccountRepository $accountRepo,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $messageBus,
    ) {}

    /**
     * @param array<string, mixed> $update
     * @param array<string, mixed> $state
     */
    public function handle(int $chatId, array $update, array $state): void
    {
        $step = $state['step'] ?? 0;

        match ($step) {
            0 => $this->handleAccountSelection($chatId, $update, $state),
            1 => $this->handleFileUpload($chatId, $update, $state),
            default => $this->conversationManager->end($chatId),
        };
    }

    /**
     * @param array<string, mixed> $update
     * @param array<string, mixed> $state
     */
    private function handleAccountSelection(int $chatId, array $update, array $state): void
    {
        $callbackData = $update['callback_query']['data'] ?? null;

        if ($callbackData === null || !str_starts_with($callbackData, 'import:account:')) {
            $this->sender->sendMessage($chatId, 'Please select an account using the buttons provided.');
            return;
        }

        $accountId = substr($callbackData, strlen('import:account:'));

        $state['data']['accountId'] = $accountId;
        $state['step']              = 1;
        $this->conversationManager->updateState($chatId, $state);

        $this->sender->sendMessage(
            $chatId,
            'Please send the bank statement file (MT940 / .sta or Sparkasse CSV).',
        );
    }

    /**
     * @param array<string, mixed> $update
     * @param array<string, mixed> $state
     */
    private function handleFileUpload(int $chatId, array $update, array $state): void
    {
        $document = $update['message']['document'] ?? null;

        if ($document === null) {
            $this->sender->sendMessage($chatId, 'Please send the bank statement as a file attachment (MT940 or CSV).');
            return;
        }

        $fileId   = $document['file_id'];
        $fileName = $document['file_name'] ?? 'import';

        // Download file from Telegram to local storage
        $localPath = $this->telegramFileDownloader->download($fileId, 'imports');

        if ($localPath === null) {
            $this->sender->sendMessage($chatId, 'Failed to download the file. Please try again.');
            return;
        }

        // Detect the file format
        try {
            $format = $this->formatDetector->detect($localPath, $fileName);
        } catch (UnsupportedFormatException $e) {
            @unlink($localPath);
            $this->sender->sendMessage(
                $chatId,
                'Unsupported file format. Please send an MT940 (.sta) or Sparkasse CSV file.',
            );
            $this->conversationManager->end($chatId);
            return;
        }

        $user    = $this->userRepo->findByTelegramChatId($chatId);
        $account = $user !== null
            ? $this->accountRepo->findByFamilyAndId($user->getFamily(), $state['data']['accountId'] ?? '')
            : null;

        if ($user === null || $account === null) {
            $this->sender->sendMessage($chatId, 'Error: account not found. Please start again: /import');
            $this->conversationManager->end($chatId);
            return;
        }

        $importFile = new ImportFile();
        $importFile
            ->setOriginalFilename($fileName)
            ->setStoragePath($localPath)
            ->setFormat($format)
            ->setAccount($account)
            ->setUploadedBy($user);

        $this->em->persist($importFile);
        $this->em->flush();

        // Dispatch to Messenger queue for background processing
        $this->messageBus->dispatch(new ProcessImportMessage((string) $importFile->getId()));

        $this->conversationManager->end($chatId);

        $this->sender->sendMessage(
            $chatId,
            "File received! Processing in the background...\n\nYou will be notified when the import is complete.",
        );
    }
}
