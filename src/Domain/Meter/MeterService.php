<?php

declare(strict_types=1);

namespace App\Domain\Meter;

use App\Entity\Family;
use App\Entity\MeterReading;
use App\Entity\MeterType;
use App\Entity\User;
use App\Repository\MeterReadingRepository;
use Doctrine\ORM\EntityManagerInterface;

class MeterService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MeterReadingRepository $meterReadingRepository,
    ) {}

    public function saveReading(
        Family $family,
        User $createdBy,
        MeterType $type,
        float $value,
        \DateTimeImmutable $readingDate,
        ?string $photoPath = null,
        ?string $notes = null,
    ): MeterReading {
        $reading = new MeterReading();
        $reading
            ->setFamily($family)
            ->setCreatedBy($createdBy)
            ->setType($type)
            ->setValue($value)
            ->setReadingDate($readingDate)
            ->setPhotoPath($photoPath)
            ->setNotes($notes);

        $this->em->persist($reading);
        $this->em->flush();

        return $reading;
    }

    /**
     * Returns readings with computed consumption delta.
     * Each item: ['reading' => MeterReading, 'consumption' => float|null]
     *
     * @return array<int, array{reading: MeterReading, consumption: float|null}>
     */
    public function getReadingsWithConsumption(Family $family, MeterType $type): array
    {
        $readings = $this->meterReadingRepository->findByFamilyAndType($family, $type);
        $result   = [];
        $prev     = null;

        foreach ($readings as $reading) {
            $consumption = $prev !== null ? round($reading->getValue() - $prev->getValue(), 2) : null;
            $result[]    = ['reading' => $reading, 'consumption' => $consumption];
            $prev        = $reading;
        }

        return array_reverse($result);
    }

    /**
     * Returns Chart.js-ready data: labels (dates) and consumption values.
     *
     * @return array{labels: string[], data: float[]}
     */
    public function getChartData(Family $family, MeterType $type): array
    {
        $readings = $this->meterReadingRepository->findByFamilyAndType($family, $type);

        $labels = [];
        $data   = [];
        $prev   = null;

        foreach ($readings as $reading) {
            if ($prev !== null) {
                $labels[] = $reading->getReadingDate()->format('Y-m-d');
                $data[]   = round($reading->getValue() - $prev->getValue(), 2);
            }
            $prev = $reading;
        }

        return ['labels' => $labels, 'data' => $data];
    }
}
