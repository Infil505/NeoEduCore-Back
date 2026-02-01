<?php

namespace App\Domain\Exams;

class QuestionRules
{
    public function canDeleteQuestion(int $questionCount): bool
    {
        return $questionCount > 1;
    }
}