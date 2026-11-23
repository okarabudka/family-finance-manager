<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Transaction;
use App\Repository\TransactionRepository;

final class TelegramWebhookTest extends FunctionalTestCase
{
    private const string SECRET = 'test-webhook-secret';

    public function testWrongSecretIsForbidden(): void
    {
        $this->postUpdate('/balance', 'wrong-secret');

        self::assertResponseStatusCodeSame(403);
    }

    public function testMissingSecretIsForbidden(): void
    {
        $this->postUpdate('/balance', null);

        self::assertResponseStatusCodeSame(403);
    }

    public function testRecordedCommandIsAccepted(): void
    {
        $this->postUpdate('/balance', self::SECRET);

        self::assertResponseIsSuccessful();
    }

    public function testInvalidJsonIsRejected(): void
    {
        $this->client->request('POST', '/telegram/webhook', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => self::SECRET,
        ], content: '{not json');

        self::assertResponseStatusCodeSame(400);
    }

    public function testQuickInputRecordsAnExpenseForTheLinkedUser(): void
    {
        $this->postUpdate('12.50 coffee from the bot', self::SECRET);

        self::assertResponseIsSuccessful();
        $transaction = static::getContainer()->get(TransactionRepository::class)
            ->findOneBy(['description' => 'coffee from the bot']);
        self::assertInstanceOf(Transaction::class, $transaction);
        self::assertSame('12.50', $transaction->getAmount());
        self::assertSame($this->demoUser()->getId()->toRfc4122(), $transaction->getCreatedBy()->getId()->toRfc4122());
    }

    private function postUpdate(string $text, ?string $secret): void
    {
        $payload = file_get_contents(\dirname(__DIR__).'/Fixtures/telegram/message.json');
        self::assertIsString($payload);
        $payload = str_replace('__TEXT__', $text, $payload);

        $server = ['CONTENT_TYPE' => 'application/json'];
        if (null !== $secret) {
            $server['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] = $secret;
        }

        $this->client->request('POST', '/telegram/webhook', server: $server, content: $payload);
    }
}
