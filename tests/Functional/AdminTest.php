<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Transaction;
use App\Repository\TransactionRepository;

final class AdminTest extends FunctionalTestCase
{
    public function testAdminShowsAmountsInEurosNotCents(): void
    {
        $user = $this->loginAsDemoUser();
        $transaction = static::getContainer()->get(TransactionRepository::class)
            ->findOneBy(['family' => $user->getFamily(), 'description' => 'Rent']);
        self::assertInstanceOf(Transaction::class, $transaction);

        $this->client->request('GET', \sprintf('/admin/transaction/%s', $transaction->getId()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', number_format((float) $transaction->getAmount(), 2));
    }
}
