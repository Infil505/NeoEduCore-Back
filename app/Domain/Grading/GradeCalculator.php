<?php

namespace App\Domain\Grading;

class GradeCalculator
{
    public function percentage(float $score, float $total): float
    {
        if ($total <= 0) return 0.0;
        return round(($score / $total) * 100, 2);
    }
}