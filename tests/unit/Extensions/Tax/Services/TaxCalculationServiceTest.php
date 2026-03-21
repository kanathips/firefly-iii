<?php

declare(strict_types=1);

namespace Tests\unit\Extensions\Tax\Services;

use Carbon\Carbon;
use FireflyIII\Extensions\Tax\Repositories\TaxRepositoryInterface;
use FireflyIII\Extensions\Tax\Services\TaxCalculationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(TaxCalculationService::class)]
final class TaxCalculationServiceTest extends TestCase
{
    private TaxCalculationService $service;

    /** @var MockObject&TaxRepositoryInterface */
    private MockObject $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->createMock(TaxRepositoryInterface::class);
        $this->service    = new TaxCalculationService($this->repository);
    }

    // -----------------------------------------------------------------------
    // computeDeductibleTotals
    // -----------------------------------------------------------------------

    public function testComputeDeductibleTotalsReturnsSummedAmounts(): void
    {
        $profileId = 1;
        $start     = Carbon::parse('2025-01-01');
        $end       = Carbon::parse('2025-12-31');

        $this->repository
            ->expects($this->once())
            ->method('getDeductibleJournals')
            ->with($profileId, $start, $end)
            ->willReturn([
                ['amount' => '-50.00', 'category' => 'Medical', 'date' => '2025-03-15'],
                ['amount' => '-120.00', 'category' => 'Medical', 'date' => '2025-06-10'],
                ['amount' => '-80.00', 'category' => 'Office', 'date' => '2025-09-20'],
            ]);

        $result = $this->service->computeDeductibleTotals($profileId, $start, $end);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('by_category', $result);
        $this->assertEqualsWithDelta(250.00, $result['total'], 0.001);
        $this->assertEqualsWithDelta(170.00, $result['by_category']['Medical'], 0.001);
        $this->assertEqualsWithDelta(80.00, $result['by_category']['Office'], 0.001);
    }

    public function testComputeDeductibleTotalsReturnsZeroForEmptyJournals(): void
    {
        $profileId = 1;
        $start     = Carbon::parse('2025-01-01');
        $end       = Carbon::parse('2025-12-31');

        $this->repository
            ->expects($this->once())
            ->method('getDeductibleJournals')
            ->willReturn([]);

        $result = $this->service->computeDeductibleTotals($profileId, $start, $end);

        $this->assertSame(0.0, $result['total']);
        $this->assertSame([], $result['by_category']);
    }

    public function testComputeDeductibleTotalsHandlesNullCategory(): void
    {
        $profileId = 1;
        $start     = Carbon::parse('2025-01-01');
        $end       = Carbon::parse('2025-12-31');

        $this->repository
            ->expects($this->once())
            ->method('getDeductibleJournals')
            ->willReturn([
                ['amount' => '-30.00', 'category' => null, 'date' => '2025-04-01'],
            ]);

        $result = $this->service->computeDeductibleTotals($profileId, $start, $end);

        $this->assertEqualsWithDelta(30.00, $result['total'], 0.001);
        $this->assertArrayHasKey('(none)', $result['by_category']);
        $this->assertEqualsWithDelta(30.00, $result['by_category']['(none)'], 0.001);
    }

    public function testComputeDeductibleTotalsAbsoluteValueOfNegativeAmounts(): void
    {
        $profileId = 42;
        $start     = Carbon::parse('2025-01-01');
        $end       = Carbon::parse('2025-12-31');

        $this->repository
            ->expects($this->once())
            ->method('getDeductibleJournals')
            ->willReturn([
                ['amount' => '-99.99', 'category' => 'Transport', 'date' => '2025-01-15'],
            ]);

        $result = $this->service->computeDeductibleTotals($profileId, $start, $end);

        $this->assertGreaterThan(0, $result['total']);
        $this->assertEqualsWithDelta(99.99, $result['total'], 0.001);
    }

    // -----------------------------------------------------------------------
    // groupByPeriod
    // -----------------------------------------------------------------------

    public function testGroupByPeriodMonthly(): void
    {
        $journals = [
            ['amount' => '-50.00', 'category' => 'Medical', 'date' => '2025-01-10'],
            ['amount' => '-20.00', 'category' => 'Medical', 'date' => '2025-01-25'],
            ['amount' => '-80.00', 'category' => 'Office',  'date' => '2025-02-15'],
        ];

        $result = $this->service->groupByPeriod($journals, 'month');

        $this->assertArrayHasKey('2025-01', $result);
        $this->assertArrayHasKey('2025-02', $result);
        $this->assertEqualsWithDelta(70.00, $result['2025-01']['total'], 0.001);
        $this->assertEqualsWithDelta(80.00, $result['2025-02']['total'], 0.001);
    }

    public function testGroupByPeriodYearly(): void
    {
        $journals = [
            ['amount' => '-100.00', 'category' => 'Medical', 'date' => '2024-06-01'],
            ['amount' => '-200.00', 'category' => 'Office',  'date' => '2025-03-01'],
        ];

        $result = $this->service->groupByPeriod($journals, 'year');

        $this->assertArrayHasKey('2024', $result);
        $this->assertArrayHasKey('2025', $result);
        $this->assertEqualsWithDelta(100.00, $result['2024']['total'], 0.001);
        $this->assertEqualsWithDelta(200.00, $result['2025']['total'], 0.001);
    }

    public function testGroupByPeriodReturnsEmptyForNoJournals(): void
    {
        $result = $this->service->groupByPeriod([], 'month');

        $this->assertSame([], $result);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function invalidPeriodProvider(): array
    {
        return [
            'week'    => ['week'],
            'quarter' => ['quarter'],
            'invalid' => ['invalid'],
        ];
    }

    #[DataProvider('invalidPeriodProvider')]
    public function testGroupByPeriodThrowsForUnsupportedPeriod(string $period): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->groupByPeriod([], $period);
    }

    // -----------------------------------------------------------------------
    // computeTaxRate
    // -----------------------------------------------------------------------

    public function testComputeTaxRateAppliesRateCorrectly(): void
    {
        $result = $this->service->computeTaxRate(1000.00, 20.0);

        $this->assertEqualsWithDelta(200.00, $result, 0.001);
    }

    public function testComputeTaxRateReturnsZeroForZeroAmount(): void
    {
        $result = $this->service->computeTaxRate(0.0, 20.0);

        $this->assertSame(0.0, $result);
    }

    public function testComputeTaxRateReturnsZeroForZeroRate(): void
    {
        $result = $this->service->computeTaxRate(500.00, 0.0);

        $this->assertSame(0.0, $result);
    }

    public function testComputeTaxRateThrowsForNegativeRate(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->computeTaxRate(500.00, -5.0);
    }

    public function testComputeTaxRateThrowsForRateOver100(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->computeTaxRate(500.00, 101.0);
    }

    // -----------------------------------------------------------------------
    // buildSummary
    // -----------------------------------------------------------------------

    public function testBuildSummaryReturnsMandatoryKeys(): void
    {
        $profileId = 5;
        $start     = Carbon::parse('2025-01-01');
        $end       = Carbon::parse('2025-12-31');

        $this->repository
            ->expects($this->once())
            ->method('getDeductibleJournals')
            ->willReturn([
                ['amount' => '-200.00', 'category' => 'Medical', 'date' => '2025-07-01'],
            ]);

        $summary = $this->service->buildSummary($profileId, $start, $end, 'month');

        $this->assertArrayHasKey('profile_id', $summary);
        $this->assertArrayHasKey('start', $summary);
        $this->assertArrayHasKey('end', $summary);
        $this->assertArrayHasKey('total_deductible', $summary);
        $this->assertArrayHasKey('by_category', $summary);
        $this->assertArrayHasKey('by_period', $summary);
        $this->assertSame($profileId, $summary['profile_id']);
        $this->assertEqualsWithDelta(200.00, $summary['total_deductible'], 0.001);
    }
}
