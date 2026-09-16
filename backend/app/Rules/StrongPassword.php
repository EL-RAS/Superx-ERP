<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class StrongPassword implements ValidationRule
{
    private const REQUIREMENTS = [
        'at least 8 characters' => '/.{8,}/',
        'an uppercase letter' => '/[A-Z]/',
        'a lowercase letter' => '/[a-z]/',
        'a number' => '/\d/',
        'a special character (@$!%*?&)' => '/[@$!%*?&]/',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $password = (string) $value;

        foreach (self::REQUIREMENTS as $label => $pattern) {
            if (preg_match($pattern, $password) !== 1) {
                $fail('Password must be at least 8 characters and contain at least one uppercase letter, one lowercase letter, one number, and one special character (@$!%*?&).');

                return;
            }
        }
    }
}
