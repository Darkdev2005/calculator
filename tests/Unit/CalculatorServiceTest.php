<?php

namespace Tests\Unit;

use App\Exceptions\CalculatorException;
use App\Services\CalculatorService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CalculatorServiceTest extends TestCase
{
    private CalculatorService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new CalculatorService();
    }

    #[Test]
    public function it_adds_numbers(): void
    {
        $result = $this->service->calculate('add', 8, 4);

        $this->assertSame(12.0, $result['result']);
        $this->assertSame('+', $result['symbol']);
    }

    #[Test]
    public function it_subtracts_numbers(): void
    {
        $result = $this->service->calculate('subtract', 8, 4);

        $this->assertSame(4.0, $result['result']);
        $this->assertSame('-', $result['symbol']);
    }

    #[Test]
    public function it_multiplies_numbers(): void
    {
        $result = $this->service->calculate('multiply', 8, 4);

        $this->assertSame(32.0, $result['result']);
        $this->assertSame('*', $result['symbol']);
    }

    #[Test]
    public function it_divides_numbers(): void
    {
        $result = $this->service->calculate('divide', 8, 4);

        $this->assertSame(2.0, $result['result']);
        $this->assertSame('/', $result['symbol']);
    }

    #[Test]
    public function it_calculates_power(): void
    {
        $result = $this->service->calculate('power', 2, 5);

        $this->assertSame(32.0, $result['result']);
        $this->assertSame('^', $result['symbol']);
    }

    #[Test]
    public function it_calculates_modulo(): void
    {
        $result = $this->service->calculate('modulo', 10, 3);

        $this->assertSame(1.0, $result['result']);
        $this->assertSame('%', $result['symbol']);
    }

    #[Test]
    public function it_applies_result_precision(): void
    {
        $result = $this->service->calculate('divide', 10, 3, 2);

        $this->assertSame(3.33, $result['result']);
    }

    #[Test]
    public function it_rejects_invalid_precision(): void
    {
        $this->expectException(CalculatorException::class);
        $this->expectExceptionMessage('Precision must be an integer between 0 and 10.');

        $this->service->calculate('divide', 10, 3, 99);
    }

    #[Test]
    public function it_rejects_division_by_zero(): void
    {
        $this->expectException(CalculatorException::class);
        $this->expectExceptionMessage('Division by zero is not allowed.');

        $this->service->calculate('divide', 8, 0);
    }

    #[Test]
    public function it_rejects_modulo_by_zero(): void
    {
        $this->expectException(CalculatorException::class);
        $this->expectExceptionMessage('Modulo by zero is not allowed.');

        $this->service->calculate('modulo', 8, 0);
    }

    #[Test]
    public function it_rejects_invalid_operation(): void
    {
        $this->expectException(CalculatorException::class);

        $this->service->calculate('powering', 8, 2);
    }

    #[Test]
    public function it_rejects_non_numeric_operands(): void
    {
        $this->expectException(CalculatorException::class);

        $this->service->calculate('add', 'abc', 2);
    }
}
