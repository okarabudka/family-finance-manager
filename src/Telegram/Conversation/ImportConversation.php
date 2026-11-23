<?php

declare(strict_types=1);

namespace App\Telegram\Conversation;

use App\Domain\Import\FormatDetector;
use App\Domain\Import\UnsupportedFormatException;
use App\Entity\ImportFile;
use App\Message\ProcessImportMessage;
use App\Repository\AccountRepository;
use App\Repository\UserRepository;
use App\Telegram\BotTranslator;
use App\Telegram\ConversationInterface;
use App\Telegram\ConversationManager;
use App\Telegram\Payload;
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
        private readonly BotTranslator $translator,
    ) {
    }

    /**
     * @param array<string, mixed> $update
     * @param array<string, mixed> $state
     */
    public function handle(int $chatId, array $update, array $state): void
    {
        $step = Payload::int($state, 'step') ?? 0;

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
        $callbackData = Payload::callbackData($update);

        if (null === $callbackData || !str_starts_with($callbackData, 'import:account:')) {
            $this->sender->sendMessage($chatId, $this->translator->trans('import.choose_account_from_buttons'));

            return;
        }

        $accountId = substr($callbackData, strlen('import:account:'));

        $state['data'] = [...Payload::array($state, 'data'), 'accountId' => $accountId];
        $state['step'] = 1;
        $this->conversationManager->updateState($chatId, $state);

        $this->sender->sendMessage(
            $chatId,
            $this->translator->trans('import.send_file'),
        );
    }

    /**
     * @param array<string, mixed> $update
     * @param array<string, mixed> $state
     */
    private function handleFileUpload(int $chatId, array $update, array $state): void
    {
        $fileId = Payload::string($update, 'message', 'document', 'file_id');
        $fileName = Payload::string($update, 'message', 'document', 'file_name') ?? 'import';

        if (null === $fileId) {
            $this->sender->sendMessage($chatId, $this->translator->trans('import.send_as_attachment'));

            return;
        }

        // Download file from Telegram to local storage
        $localPath = $this->telegramFileDownloader->download($fileId, 'imports');

        if (null === $localPath) {
            $this->sender->sendMessage($chatId, $this->translator->trans('import.download_failed'));

            return;
        }

        // Detect the file format
        try {
            $format = $this->formatDetector->detect($localPath, $fileName);
        } catch (UnsupportedFormatException $e) {
            @unlink($localPath);
            $this->sender->sendMessage(
                $chatId,
                $this->translator->trans('import.unsupported_format'),
            );
            $this->conversationManager->end($chatId);

            return;
        }

        $user = $this->userRepo->findByTelegramChatId($chatId);
        $family = $user?->getFamily();
        $account = null !== $family
            ? $this->accountRepo->findByFamilyAndId($family, Payload::string($state, 'data', 'accountId') ?? '')
            : null;

        if (null === $user || null === $account) {
            $this->sender->sendMessage($chatId, $this->translator->trans('import.account_not_found'));
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
            $this->translator->trans('import.file_received'),
        );
    }
}
