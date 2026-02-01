<?php

namespace Tests\Unit\Grading;

use PHPUnit\Framework\TestCase;
use App\Domain\Grading\GradeCalculator;

class GradeCalculatorTest extends TestCase
{
    public function test_percentage_rounds_to_2_decimals(): void
    {
        $calc = new GradeCalculator();

        $this->assertSame(88.89, $calc->percentage(17.777777, 20));
        $this->assertSame(0.0, $calc->percentage(10, 0));
    }
}