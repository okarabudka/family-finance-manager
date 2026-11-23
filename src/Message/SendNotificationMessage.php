<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Dispatched when a notification needs to be sent to a user (e.g. via Telegram or e-mail).
 * Handled asynchronously by SendNotificationMessageHandler.
 */
final readonly class SendNotificationMessage
{
    public function __construct(
        /** User ID (UUID string) of the recipient */
        public string $userId,

        /** Notification channel: 'telegram', 'email', etc. */
        public string $channel,

        /** The notification text or template key */
        public string $message,

        /** @var array<string, mixed> Optional extra context data for template rendering */
        public array $context = [],
    ) {
    }
}
