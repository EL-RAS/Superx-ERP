<?php

namespace App\Rules;

use App\Services\PhoneNormalizer;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidPhone implements ValidationRule
{
    public function __construct(private string $defaultRegion = 'JO') {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! PhoneNormalizer::isValid((string) $value, $this->defaultRegion)) {
            $fail('The :attribute is not a valid phone number. Use international format like +962785555555.');
        }
    }
}
