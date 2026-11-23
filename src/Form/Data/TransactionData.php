<?php

declare(strict_types=1);

namespace App\Form\Data;

use App\Entity\Account;
use App\Entity\Category;
use App\Entity\Transaction;
use App\Entity\TransactionType;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Form model of TransactionFormType.
 */
final class TransactionData
{
    public TransactionType $type = TransactionType::Expense;

    #[Assert\NotBlank]
    #[Assert\Positive]
    public ?string $amount = null;

    public ?string $description = null;

    public ?Category $category = null;

    #[Assert\NotNull]
    public ?Account $account = null;

    public ?Account $targetAccount = null;

    #[Assert\NotNull]
    public ?\DateTimeImmutable $executedAt;

    public function __construct()
    {
        $this->executedAt = new \DateTimeImmutable('today');
    }

    public static function fromTransaction(Transaction $transaction): self
    {
        $data = new self();
        $data->type = $transaction->getType();
        $data->amount = $transaction->getAmount();
        $data->description = $transaction->getDescription();
        $data->category = $transaction->getCategory();
        $data->account = $transaction->getSourceAccount() ?? $transaction->getTargetAccount();
        $data->targetAccount = null !== $transaction->getSourceAccount() ? $transaction->getTargetAccount() : null;
        $data->executedAt = $transaction->getExecutedAt();

        return $data;
    }
}
