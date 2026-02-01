<?php

namespace App\Domain\Auth;

class PasswordPolicy
{
    public function isValid(string $password): bool
    {
        if (mb_strlen($password) < 8) return false;

        $hasUpper = preg_match('/[A-Z]/', $password) === 1;
        $hasLower = preg_match('/[a-z]/', $password) === 1;
        $hasDigit = preg_match('/\d/', $password) === 1;

        return $hasUpper && $hasLower && $hasDigit;
    }
}