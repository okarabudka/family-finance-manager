<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'transactions')]
#[ORM\Index(columns: ['executed_at'])]
#[ORM\Index(columns: ['family_id', 'executed_at'])]
class Transaction
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    private Uuid $id;

    #[ORM\Column(enumType: TransactionType::class)]
    private TransactionType $type;

    #[ORM\Column(type: 'decimal', precision: 15, scale: 2)]
    private string $amount;

    #[ORM\Column(length: 3)]
    private string $currency = 'EUR';

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $executedAt;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    private ?Account $sourceAccount = null;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    private ?Account $targetAccount = null;

    #[ORM\ManyToOne(targetEntity: Category::class)]
    private ?Category $category = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $createdBy;

    #[ORM\ManyToOne(targetEntity: Family::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Family $family;

    #[ORM\OneToOne(targetEntity: ImportTransaction::class)]
    private ?ImportTransaction $importOrigin = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $isInternalTransfer = false;

    #[ORM\Column(options: ['default' => true])]
    private bool $isConfirmed = true;

    /** @var string[]|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $tags = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getType(): TransactionType
    {
        return $this->type;
    }

    public function setType(TransactionType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): static
    {
        $this->amount = $amount;

        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): static
    {
        $this->currency = $currency;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getExecutedAt(): \DateTimeImmutable
    {
        return $this->executedAt;
    }

    public function setExecutedAt(\DateTimeImmutable $executedAt): static
    {
        $this->executedAt = $executedAt;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getSourceAccount(): ?Account
    {
        return $this->sourceAccount;
    }

    public function setSourceAccount(?Account $sourceAccount): static
    {
        $this->sourceAccount = $sourceAccount;

        return $this;
    }

    public function getTargetAccount(): ?Account
    {
        return $this->targetAccount;
    }

    public function setTargetAccount(?Account $targetAccount): static
    {
        $this->targetAccount = $targetAccount;

        return $this;
    }

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function setCategory(?Category $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getCreatedBy(): User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(User $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getFamily(): Family
    {
        return $this->family;
    }

    public function setFamily(Family $family): static
    {
        $this->family = $family;

        return $this;
    }

    public function getImportOrigin(): ?ImportTransaction
    {
        return $this->importOrigin;
    }

    public function setImportOrigin(?ImportTransaction $importOrigin): static
    {
        $this->importOrigin = $importOrigin;

        return $this;
    }

    public function isInternalTransfer(): bool
    {
        return $this->isInternalTransfer;
    }

    public function setIsInternalTransfer(bool $isInternalTransfer): static
    {
        $this->isInternalTransfer = $isInternalTransfer;

        return $this;
    }

    public function isConfirmed(): bool
    {
        return $this->isConfirmed;
    }

    public function setIsConfirmed(bool $isConfirmed): static
    {
        $this->isConfirmed = $isConfirmed;

        return $this;
    }

    /** @return string[]|null */
    public function getTags(): ?array
    {
        return $this->tags;
    }

    /** @param string[]|null $tags */
    public function setTags(?array $tags): static
    {
        $this->tags = $tags;

        return $this;
    }
}
