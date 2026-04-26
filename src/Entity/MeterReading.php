<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: \App\Repository\MeterReadingRepository::class)]
#[ORM\Table(name: 'meter_readings')]
#[ORM\Index(columns: ['family_id', 'type', 'reading_date'], name: 'idx_meter_readings_family_type_date')]
class MeterReading
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Family::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Family $family;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $createdBy;

    #[ORM\Column(enumType: MeterType::class)]
    private MeterType $type;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $value;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $readingDate;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $photoPath = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
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

    public function getCreatedBy(): User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(User $createdBy): static
    {
        $this->createdBy = $createdBy;
        return $this;
    }

    public function getType(): MeterType
    {
        return $this->type;
    }

    public function setType(MeterType $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function getValue(): float
    {
        return (float) $this->value;
    }

    public function setValue(float $value): static
    {
        $this->value = (string) $value;
        return $this;
    }

    public function getReadingDate(): \DateTimeImmutable
    {
        return $this->readingDate;
    }

    public function setReadingDate(\DateTimeImmutable $readingDate): static
    {
        $this->readingDate = $readingDate;
        return $this;
    }

    public function getPhotoPath(): ?string
    {
        return $this->photoPath;
    }

    public function setPhotoPath(?string $photoPath): static
    {
        $this->photoPath = $photoPath;
        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
