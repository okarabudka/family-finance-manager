<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Meter;

use App\Domain\Meter\MeterService;
use App\Entity\Family;
use App\Entity\MeterReading;
use App\Entity\MeterType;
use App\Entity\User;
use App\Repository\MeterReadingRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class MeterServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private MeterReadingRepository&MockObject $repo;
    private MeterService $service;

    private Family $family;
    private User $user;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->repo = $this->createMock(MeterReadingRepository::class);
        $this->service = new MeterService($this->em, $this->repo);

        $this->family = new Family();
        $this->family->setName('Test Family')->setCurrency('EUR');

        $this->user = new User();
        $this->user->setName('Test')->setEmail('t@t.com')->setPassword('')->setFamily($this->family);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function makeReading(float $value, string $date): MeterReading
    {
        $r = new MeterReading();
        $r->setFamily($this->family)
          ->setCreatedBy($this->user)
          ->setType(MeterType::Electricity)
          ->setValue($value)
          ->setReadingDate(new \DateTimeImmutable($date));

        return $r;
    }

    // ── saveReading ───────────────────────────────────────────────────────────

    public function testSaveReadingPersistsAndFlushes(): void
    {
        $this->em->expects(self::once())->method('persist')->with(self::isInstanceOf(MeterReading::class));
        $this->em->expects(self::once())->method('flush');

        $result = $this->service->saveReading(
            family: $this->family,
            createdBy: $this->user,
            type: MeterType::Electricity,
            value: 12345.67,
            readingDate: new \DateTimeImmutable('2025-06-01'),
        );

        self::assertSame(MeterType::Electricity, $result->getType());
        self::assertEqualsWithDelta(12345.67, $result->getValue(), 0.001);
        self::assertSame('2025-06-01', $result->getReadingDate()->format('Y-m-d'));
        self::assertNull($result->getPhotoPath());
        self::assertNull($result->getNotes());
    }

    public function testSaveReadingStoresOptionalFields(): void
    {
        $this->em->method('persist');
        $this->em->method('flush');

        $result = $this->service->saveReading(
            family: $this->family,
            createdBy: $this->user,
            type: MeterType::Water,
            value: 234.5,
            readingDate: new \DateTimeImmutable('2025-06-01'),
            photoPath: '/uploads/meters/abc.jpg',
            notes: 'Read after repairs',
        );

        self::assertSame('/uploads/meters/abc.jpg', $result->getPhotoPath());
        self::assertSame('Read after repairs', $result->getNotes());
        self::assertSame(MeterType::Water, $result->getType());
    }

    // ── getReadingsWithConsumption ────────────────────────────────────────────

    public function testConsumptionEmptyWhenNoReadings(): void
    {
        $this->repo->method('findByFamilyAndType')->willReturn([]);

        $result = $this->service->getReadingsWithConsumption($this->family, MeterType::Electricity);

        self::assertSame([], $result);
    }

    public function testConsumptionNullForSingleReading(): void
    {
        $reading = $this->makeReading(10000.0, '2025-06-01');
        $this->repo->method('findByFamilyAndType')->willReturn([$reading]);

        $result = $this->service->getReadingsWithConsumption($this->family, MeterType::Electricity);

        self::assertCount(1, $result);
        self::assertNull($result[0]['consumption']);
        self::assertSame($reading, $result[0]['reading']);
    }

    public function testConsumptionCalculatedForTwoReadings(): void
    {
        // repo returns ASC order; first = earlier, second = later
        $r1 = $this->makeReading(10000.0, '2025-05-01');
        $r2 = $this->makeReading(10150.0, '2025-06-01');
        $this->repo->method('findByFamilyAndType')->willReturn([$r1, $r2]);

        $result = $this->service->getReadingsWithConsumption($this->family, MeterType::Electricity);

        // array_reverse: newest first
        self::assertCount(2, $result);
        self::assertSame($r2, $result[0]['reading']);
        self::assertEqualsWithDelta(150.0, $result[0]['consumption'], 0.001);
        self::assertSame($r1, $result[1]['reading']);
        self::assertNull($result[1]['consumption']);
    }

    public function testConsumptionForThreeReadings(): void
    {
        $r1 = $this->makeReading(10000.0, '2025-04-01');
        $r2 = $this->makeReading(10150.0, '2025-05-01');
        $r3 = $this->makeReading(10320.0, '2025-06-01');
        $this->repo->method('findByFamilyAndType')->willReturn([$r1, $r2, $r3]);

        $result = $this->service->getReadingsWithConsumption($this->family, MeterType::Electricity);

        // newest first after reverse
        self::assertCount(3, $result);
        self::assertSame($r3, $result[0]['reading']);
        self::assertEqualsWithDelta(170.0, $result[0]['consumption'], 0.001); // 10320 - 10150
        self::assertSame($r2, $result[1]['reading']);
        self::assertEqualsWithDelta(150.0, $result[1]['consumption'], 0.001); // 10150 - 10000
        self::assertSame($r1, $result[2]['reading']);
        self::assertNull($result[2]['consumption']);
    }

    public function testConsumptionRoundedToTwoDecimals(): void
    {
        $r1 = $this->makeReading(100.0, '2025-05-01');
        $r2 = $this->makeReading(100.333, '2025-06-01');
        $this->repo->method('findByFamilyAndType')->willReturn([$r1, $r2]);

        $result = $this->service->getReadingsWithConsumption($this->family, MeterType::Electricity);

        self::assertEqualsWithDelta(0.33, $result[0]['consumption'], 0.001);
    }

    public function testConsumptionIsNegativeWhenValueDecreased(): void
    {
        // Meter was replaced or manually corrected to a lower value
        $r1 = $this->makeReading(10200.0, '2025-05-01');
        $r2 = $this->makeReading(100.0, '2025-06-01');
        $this->repo->method('findByFamilyAndType')->willReturn([$r1, $r2]);

        $result = $this->service->getReadingsWithConsumption($this->family, MeterType::Electricity);

        self::assertLessThan(0, $result[0]['consumption']);
    }

    // ── getChartData ──────────────────────────────────────────────────────────

    public function testChartDataEmptyWhenNoReadings(): void
    {
        $this->repo->method('findByFamilyAndType')->willReturn([]);

        $result = $this->service->getChartData($this->family, MeterType::Water);

        self::assertSame(['labels' => [], 'data' => []], $result);
    }

    public function testChartDataEmptyWhenSingleReading(): void
    {
        // Cannot compute consumption delta from one reading
        $this->repo->method('findByFamilyAndType')->willReturn([
            $this->makeReading(10000.0, '2025-06-01'),
        ]);

        $result = $this->service->getChartData($this->family, MeterType::Water);

        self::assertSame([], $result['labels']);
        self::assertSame([], $result['data']);
    }

    public function testChartDataHasOnePointForTwoReadings(): void
    {
        $r1 = $this->makeReading(10000.0, '2025-05-01');
        $r2 = $this->makeReading(10200.0, '2025-06-01');
        $this->repo->method('findByFamilyAndType')->willReturn([$r1, $r2]);

        $result = $this->service->getChartData($this->family, MeterType::Electricity);

        self::assertCount(1, $result['labels']);
        self::assertCount(1, $result['data']);
        self::assertSame('2025-06-01', $result['labels'][0]);
        self::assertEqualsWithDelta(200.0, $result['data'][0], 0.001);
    }

    public function testChartDataHasTwoPointsForThreeReadings(): void
    {
        $r1 = $this->makeReading(10000.0, '2025-04-01');
        $r2 = $this->makeReading(10150.0, '2025-05-01');
        $r3 = $this->makeReading(10320.0, '2025-06-01');
        $this->repo->method('findByFamilyAndType')->willReturn([$r1, $r2, $r3]);

        $result = $this->service->getChartData($this->family, MeterType::Electricity);

        self::assertSame(['2025-05-01', '2025-06-01'], $result['labels']);
        self::assertEqualsWithDelta(150.0, $result['data'][0], 0.001);
        self::assertEqualsWithDelta(170.0, $result['data'][1], 0.001);
    }

    public function testChartDataLabelsAreFormattedAsYmd(): void
    {
        $r1 = $this->makeReading(100.0, '2025-01-05');
        $r2 = $this->makeReading(200.0, '2025-02-03');
        $this->repo->method('findByFamilyAndType')->willReturn([$r1, $r2]);

        $result = $this->service->getChartData($this->family, MeterType::Water);

        self::assertSame('2025-02-03', $result['labels'][0]);
    }

    public function testChartDataValuesRoundedToTwoDecimals(): void
    {
        $r1 = $this->makeReading(0.0, '2025-05-01');
        $r2 = $this->makeReading(33.3333, '2025-06-01');
        $this->repo->method('findByFamilyAndType')->willReturn([$r1, $r2]);

        $result = $this->service->getChartData($this->family, MeterType::Water);

        self::assertEqualsWithDelta(33.33, $result['data'][0], 0.001);
    }
}
