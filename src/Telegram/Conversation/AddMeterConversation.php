<?php

declare(strict_types=1);

namespace App\Telegram\Conversation;

use App\Domain\Meter\MeterService;
use App\Entity\MeterType;
use App\Repository\MeterReadingRepository;
use App\Repository\UserRepository;
use App\Telegram\ConversationInterface;
use App\Telegram\ConversationManager;
use App\Telegram\TelegramFileDownloader;
use App\Telegram\TelegramSender;

class AddMeterConversation implements ConversationInterface
{
    public function __construct(
        private readonly TelegramSender $sender,
        private readonly ConversationManager $conversationManager,
        private readonly TelegramFileDownloader $telegramFileDownloader,
        private readonly MeterService $meterService,
        private readonly MeterReadingRepository $meterReadingRepository,
        private readonly UserRepository $userRepo,
    ) {}

    /**
     * @param array<string, mixed> $update
     * @param array<string, mixed> $state
     */
    public function handle(int $chatId, array $update, array $state): void
    {
        $step = $state['step'] ?? 0;

        match ($step) {
            0 => $this->handleTypeSelection($chatId, $update, $state),
            1 => $this->handleValueInput($chatId, $update, $state),
            2 => $this->handlePhotoInput($chatId, $update, $state),
            default => $this->conversationManager->end($chatId),
        };
    }

    /**
     * @param array<string, mixed> $update
     * @param array<string, mixed> $state
     */
    private function handleTypeSelection(int $chatId, array $update, array $state): void
    {
        $callbackData = $update['callback_query']['data'] ?? null;

        if ($callbackData === null || !str_starts_with($callbackData, 'meter:type:')) {
            $this->sender->sendMessage($chatId, 'Please select meter type using the buttons.');
            return;
        }

        $typeValue = substr($callbackData, strlen('meter:type:'));
        $type      = MeterType::tryFrom($typeValue);

        if ($type === null) {
            $this->sender->sendMessage($chatId, 'Invalid meter type. Please try again: /meter');
            $this->conversationManager->end($chatId);
            return;
        }

        $state['data']['type'] = $type->value;
        $state['step']         = 1;
        $this->conversationManager->updateState($chatId, $state);

        $user   = $this->userRepo->findByTelegramChatId($chatId);
        $family = $user?->getFamily();
        $hint   = '';

        if ($family !== null) {
            $latest = $this->meterReadingRepository->findLatestByFamilyAndType($family, $type);
            if ($latest !== null) {
                $hint = sprintf(
                    "\n\nLast reading: *%.2f %s* on %s",
                    $latest->getValue(),
                    $type->unit(),
                    $latest->getReadingDate()->format('Y-m-d'),
                );
            }
        }

        $this->sender->sendMessage(
            $chatId,
            sprintf("*%s %s*%s\n\nEnter meter value (%s):", $type->emoji(), $type->label(), $hint, $type->unit()),
            parseMode: 'Markdown',
        );
    }

    /**
     * @param array<string, mixed> $update
     * @param array<string, mixed> $state
     */
    private function handleValueInput(int $chatId, array $update, array $state): void
    {
        $text = trim($update['message']['text'] ?? '');

        $value = filter_var(str_replace(',', '.', $text), FILTER_VALIDATE_FLOAT);

        if ($value === false || $value < 0) {
            $this->sender->sendMessage($chatId, 'Invalid value. Please enter a positive number (e.g. 12345.67):');
            return;
        }

        $state['data']['value'] = $value;
        $state['step']          = 2;
        $this->conversationManager->updateState($chatId, $state);

        $this->sender->sendMessage(
            $chatId,
            "Value *" . number_format($value, 2) . "* saved.\n\nNow send a photo of the meter (or type *skip* to skip):",
            parseMode: 'Markdown',
        );
    }

    /**
     * @param array<string, mixed> $update
     * @param array<string, mixed> $state
     */
    private function handlePhotoInput(int $chatId, array $update, array $state): void
    {
        $text      = strtolower(trim($update['message']['text'] ?? ''));
        $photos    = $update['message']['photo'] ?? [];
        $photoPath = null;

        $hasPhoto = !empty($photos);
        $isSkip   = $text === 'skip';

        if (!$hasPhoto && !$isSkip) {
            $this->sender->sendMessage($chatId, 'Please send a photo or type *skip*:', parseMode: 'Markdown');
            return;
        }

        if ($hasPhoto) {
            // Telegram sends multiple sizes — pick largest (last)
            $largestPhoto = end($photos);
            $fileId       = $largestPhoto['file_id'];
            $photoPath    = $this->telegramFileDownloader->download($fileId, 'meters');

            if ($photoPath === null) {
                $this->sender->sendMessage($chatId, 'Failed to download photo, saving without it.');
            }
        }

        $this->saveReading($chatId, $state, $photoPath);
    }

    /** @param array<string, mixed> $state */
    private function saveReading(int $chatId, array $state, ?string $photoPath): void
    {
        $user = $this->userRepo->findByTelegramChatId($chatId);

        if ($user === null || $user->getFamily() === null) {
            $this->sender->sendMessage($chatId, 'Session error. Please try again: /meter');
            $this->conversationManager->end($chatId);
            return;
        }

        $type  = MeterType::from($state['data']['type']);
        $value = (float) $state['data']['value'];

        $reading = $this->meterService->saveReading(
            family:      $user->getFamily(),
            createdBy:   $user,
            type:        $type,
            value:       $value,
            readingDate: new \DateTimeImmutable('today'),
            photoPath:   $photoPath,
        );

        $this->conversationManager->end($chatId);

        $photoText = $photoPath !== null ? ' (photo saved)' : '';
        $this->sender->sendMessage(
            $chatId,
            sprintf(
                "%s *%s* reading saved%s!\n\nValue: *%.2f %s*\nDate: %s",
                $type->emoji(),
                $type->label(),
                $photoText,
                $value,
                $type->unit(),
                $reading->getReadingDate()->format('Y-m-d'),
            ),
            parseMode: 'Markdown',
        );
    }
}
