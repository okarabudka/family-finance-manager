<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Transaction;
use App\Entity\TransactionType;
use App\Repository\TransactionRepository;

final class TransactionTest extends FunctionalTestCase
{
    public function testExpenseIsCreatedFromTheForm(): void
    {
        $this->loginAsDemoUser();
        $crawler = $this->client->request('GET', '/transactions/new');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form')->form();
        $form['transaction_form[type]'] = TransactionType::Expense->value;
        $form['transaction_form[amount]'] = '42.17';
        $form['transaction_form[description]'] = 'Functional test purchase';
        $this->client->submit($form);

        self::assertResponseRedirects('/transactions');

        $transaction = static::getContainer()->get(TransactionRepository::class)
            ->findOneBy(['description' => 'Functional test purchase']);
        self::assertInstanceOf(Transaction::class, $transaction);
        self::assertSame(TransactionType::Expense, $transaction->getType());
        self::assertSame('42.17', $transaction->getAmount());
    }

    public function testInvalidAmountShowsTheFormAgain(): void
    {
        $this->loginAsDemoUser();
        $crawler = $this->client->request('GET', '/transactions/new');

        $form = $crawler->filter('form')->form();
        $form['transaction_form[amount]'] = 'not a number';
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
    }
}
