<?php

namespace Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use App\Domain\Auth\PasswordPolicy;

class PasswordPolicyTest extends TestCase
{
    public function test_password_policy(): void
    {
        $policy = new PasswordPolicy();

        $this->assertFalse($policy->isValid('abc'));
        $this->assertFalse($policy->isValid('abcdefgh'));
        $this->assertFalse($policy->isValid('ABCDEFGH1'));
        $this->assertFalse($policy->isValid('Abcdefgh'));
        $this->assertTrue($policy->isValid('Abcdefg1'));
    }
}