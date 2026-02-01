<?php

namespace Tests\Unit\Exams;

use PHPUnit\Framework\TestCase;
use App\Domain\Exams\QuestionRules;

class QuestionRulesTest extends TestCase
{
    public function test_cannot_delete_last_question(): void
    {
        $rules = new QuestionRules();

        $this->assertFalse($rules->canDeleteQuestion(1));
        $this->assertTrue($rules->canDeleteQuestion(2));
    }
}