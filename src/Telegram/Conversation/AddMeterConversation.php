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
use App\Telegram\Payload;
use App\Telegram\TelegramSender;
use App\Telegram\BotTranslator;

class AddMeterConversation implements ConversationInterface
{
    public function __construct(
        private readonly TelegramSender $sender,
        private readonly ConversationManager $conversationManager,
        private readonly TelegramFileDownloader $telegramFileDownloader,
        private readonly MeterService $meterService,
        private readonly MeterReadingRepository $meterReadingRepository,
        private readonly UserRepository $userRepo,
        private readonly BotTranslator $translator,
    ) {}

    /**
     * @param array<string, mixed> $update
     * @param array<string, mixed> $state
     */
    public function handle(int $chatId, array $update, array $state): void
    {
        $step = Payload::int($state, 'step') ?? 0;

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
        $callbackData = Payload::callbackData($update);

        if ($callbackData === null || !str_starts_with($callbackData, 'meter:type:')) {
            $this->sender->sendMessage($chatId, $this->translator->trans('meter.choose_type_from_buttons'));
            return;
        }

        $typeValue = substr($callbackData, strlen('meter:type:'));
        $type      = MeterType::tryFrom($typeValue);

        if ($type === null) {
            $this->sender->sendMessage($chatId, $this->translator->trans('meter.invalid_type'));
            $this->conversationManager->end($chatId);
            return;
        }

        $state['data'] = [...Payload::array($state, 'data'), 'type' => $type->value];
        $state['step']         = 1;
        $this->conversationManager->updateState($chatId, $state);

        $user   = $this->userRepo->findByTelegramChatId($chatId);
        $family = $user?->getFamily();
        $hint   = '';

        if ($family !== null) {
            $latest = $this->meterReadingRepository->findLatestByFamilyAndType($family, $type);
            if ($latest !== null) {
                $hint = "\n\n".$this->translator->trans('meter.last_reading', [
                    '%value%' => sprintf('%.2f %s', $latest->getValue(), $type->unit()),
                    '%date%'  => $latest->getReadingDate()->format('d.m.Y'),
                ]);
            }
        }

        $this->sender->sendMessage(
            $chatId,
            sprintf('*%s %s*%s', $type->emoji(), $this->translator->trans('meter.type.'.$type->value), $hint)."\n\n".$this->translator->trans('meter.ask_value', ['%unit%' => $type->unit()]),
            parseMode: 'Markdown',
        );
    }

    /**
     * @param array<string, mixed> $update
     * @param array<string, mixed> $state
     */
    private function handleValueInput(int $chatId, array $update, array $state): void
    {
        $text = trim(Payload::text($update));

        $value = filter_var(str_replace(',', '.', $text), FILTER_VALIDATE_FLOAT);

        if ($value === false || $value < 0) {
            $this->sender->sendMessage($chatId, $this->translator->trans('meter.invalid_value'));
            return;
        }

        $state['data'] = [...Payload::array($state, 'data'), 'value' => $value];
        $state['step']          = 2;
        $this->conversationManager->updateState($chatId, $state);

        $this->sender->sendMessage(
            $chatId,
            $this->translator->trans('meter.ask_photo', ['%value%' => number_format($value, 2), '%skip%' => $this->translator->trans('meter.skip')]),
            parseMode: 'Markdown',
        );
    }

    /**
     * @param array<string, mixed> $update
     * @param array<string, mixed> $state
     */
    private function handlePhotoInput(int $chatId, array $update, array $state): void
    {
        $text      = mb_strtolower(trim(Payload::text($update)));
        // Telegram sends several sizes of the same photo, the largest one last
        $photos    = Payload::array($update, 'message', 'photo');
        $fileId    = $photos === [] ? null : Payload::string(Payload::stringKeys(end($photos)) ?? [], 'file_id');
        $photoPath = null;

        $hasPhoto = $fileId !== null;
        $isSkip   = \in_array($text, ['skip', mb_strtolower($this->translator->trans('meter.skip'))], true);

        if (!$hasPhoto && !$isSkip) {
            $this->sender->sendMessage($chatId, $this->translator->trans('meter.photo_or_skip', ['%skip%' => $this->translator->trans('meter.skip')]), parseMode: 'Markdown');
            return;
        }

        if ($fileId !== null) {
            $photoPath = $this->telegramFileDownloader->download($fileId, 'meters');

            if ($photoPath === null) {
                $this->sender->sendMessage($chatId, $this->translator->trans('meter.photo_download_failed'));
            }
        }

        $this->saveReading($chatId, $state, $photoPath);
    }

    /** @param array<string, mixed> $state */
    private function saveReading(int $chatId, array $state, ?string $photoPath): void
    {
        $user = $this->userRepo->findByTelegramChatId($chatId);

        if ($user === null || $user->getFamily() === null) {
            $this->sender->sendMessage($chatId, $this->translator->trans('meter.session_error'));
            $this->conversationManager->end($chatId);
            return;
        }

        $type  = MeterType::tryFrom(Payload::string($state, 'data', 'type') ?? '');
        $value = Payload::string($state, 'data', 'value');
        if ($type === null || $value === null) {
            $this->sender->sendMessage($chatId, $this->translator->trans('meter.session_error'));
            $this->conversationManager->end($chatId);
            return;
        }

        $reading = $this->meterService->saveReading(
            family:      $user->getFamily(),
            createdBy:   $user,
            type:        $type,
            value:       (float) $value,
            readingDate: new \DateTimeImmutable('today'),
            photoPath:   $photoPath,
        );

        $this->conversationManager->end($chatId);

        $photoText = $photoPath !== null ? ' '.$this->translator->trans('meter.photo_saved') : '';
        $this->sender->sendMessage(
            $chatId,
            $type->emoji().' '.$this->translator->trans('meter.saved', [
                '%type%'  => $this->translator->trans('meter.type.'.$type->value),
                '%photo%' => $photoText,
                '%value%' => sprintf('%.2f %s', $value, $type->unit()),
                '%date%'  => $reading->getReadingDate()->format('d.m.Y'),
            ]),
            parseMode: 'Markdown',
        );
    }
}
