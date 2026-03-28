<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: \App\Repository\ImportTransactionRepository::class)]
#[ORM\Table(name: 'import_transactions')]
class ImportTransaction
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ImportFile::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ImportFile $importFile;

    #[ORM\Column(type: 'decimal', precision: 15, scale: 2)]
    private string $amount;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $date;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $rawDescription = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $counterpartyName = null;

    #[ORM\Column(length: 34, nullable: true)]
    private ?string $counterpartyIban = null;

    #[ORM\ManyToOne(targetEntity: Category::class)]
    private ?Category $suggestedCategory = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $confidenceScore = null;

    #[ORM\Column(enumType: ImportTransactionStatus::class)]
    private ImportTransactionStatus $status = ImportTransactionStatus::Pending;

    #[ORM\Column(length: 64, unique: true)]
    private string $deduplicationHash;

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getImportFile(): ImportFile
    {
        return $this->importFile;
    }

    public function setImportFile(ImportFile $importFile): static
    {
        $this->importFile = $importFile;

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

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(\DateTimeImmutable $date): static
    {
        $this->date = $date;

        return $this;
    }

    public function getRawDescription(): ?string
    {
        return $this->rawDescription;
    }

    public function setRawDescription(?string $rawDescription): static
    {
        $this->rawDescription = $rawDescription;

        return $this;
    }

    public function getCounterpartyName(): ?string
    {
        return $this->counterpartyName;
    }

    public function setCounterpartyName(?string $counterpartyName): static
    {
        $this->counterpartyName = $counterpartyName;

        return $this;
    }

    public function getCounterpartyIban(): ?string
    {
        return $this->counterpartyIban;
    }

    public function setCounterpartyIban(?string $counterpartyIban): static
    {
        $this->counterpartyIban = $counterpartyIban;

        return $this;
    }

    public function getSuggestedCategory(): ?Category
    {
        return $this->suggestedCategory;
    }

    public function setSuggestedCategory(?Category $suggestedCategory): static
    {
        $this->suggestedCategory = $suggestedCategory;

        return $this;
    }

    public function getConfidenceScore(): ?float
    {
        return $this->confidenceScore;
    }

    public function setConfidenceScore(?float $confidenceScore): static
    {
        $this->confidenceScore = $confidenceScore;

        return $this;
    }

    public function getStatus(): ImportTransactionStatus
    {
        return $this->status;
    }

    public function setStatus(ImportTransactionStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getDeduplicationHash(): string
    {
        return $this->deduplicationHash;
    }

    public function setDeduplicationHash(string $deduplicationHash): static
    {
        $this->deduplicationHash = $deduplicationHash;

        return $this;
    }
}
