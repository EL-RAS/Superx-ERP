<?php

namespace App\Services;

/**
 * Lightweight E.164 phone normalizer/validator.
 *
 * Parses international prefixes (+962, 00962, or bare national numbers),
 * strips national trunk zeros (+962 078... -> +96278...), and validates
 * per-country length/format rules. Falls back to a generic E.164 shape for
 * unknown calling codes.
 */
class PhoneNormalizer
{
    /**
     * ISO region => [calling code, min national digits, max national digits].
     */
    private const COUNTRIES = [
        'JO' => ['962', 8, 9],
        'PS' => ['970', 9, 9],
        'SA' => ['966', 9, 9],
        'AE' => ['971', 9, 9],
        'KW' => ['965', 8, 8],
        'QA' => ['974', 8, 8],
        'BH' => ['973', 8, 8],
        'OM' => ['968', 8, 8],
        'LB' => ['961', 7, 8],
        'IQ' => ['964', 10, 10],
        'SY' => ['963', 9, 9],
        'YE' => ['967', 9, 9],
        'EG' => ['20', 10, 10],
        'LY' => ['218', 9, 9],
        'TN' => ['216', 8, 8],
        'DZ' => ['213', 9, 9],
        'MA' => ['212', 9, 9],
        'TR' => ['90', 10, 10],
        'US' => ['1', 10, 10],
        'CA' => ['1', 10, 10],
        'GB' => ['44', 10, 10],
        'DE' => ['49', 10, 11],
        'FR' => ['33', 9, 9],
        'ES' => ['34', 9, 9],
        'IT' => ['39', 9, 10],
        'NL' => ['31', 9, 9],
        'RU' => ['7', 10, 10],
        'IN' => ['91', 10, 10],
        'PK' => ['92', 9, 10],
        'BD' => ['880', 10, 10],
        'CN' => ['86', 11, 11],
        'JP' => ['81', 10, 10],
        'KR' => ['82', 9, 10],
        'AU' => ['61', 9, 9],
        'NG' => ['234', 10, 10],
        'ZA' => ['27', 9, 9],
        'BR' => ['55', 10, 11],
        'MX' => ['52', 10, 10],
    ];

    public static function normalize(?string $phone, string $defaultRegion = 'JO'): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        $digits = preg_replace('/[^\d+]/', '', $phone) ?? '';
        if ($digits === '') {
            return null;
        }

        $national = null;
        $region = null;

        if (str_starts_with($digits, '+')) {
            $number = substr($digits, 1);
        } elseif (str_starts_with($digits, '00')) {
            $number = substr($digits, 2);
        } else {
            $region = strtoupper($defaultRegion);
            $number = $digits;

            if (isset(self::COUNTRIES[$region])) {
                [$code] = self::COUNTRIES[$region];
                if (str_starts_with($number, $code) && strlen($number) > strlen($code)) {
                    $number = substr($number, strlen($code));
                }
            }

            $national = $number;
        }

        if ($region === null) {
            foreach (self::callingCodes() as $code => $countryRegion) {
                if (str_starts_with($number, $code)) {
                    $region = $countryRegion;
                    $national = substr($number, strlen($code));
                    break;
                }
            }

            if ($region === null) {
                return preg_match('/^\d{7,15}$/', $number) ? '+'.$number : null;
            }
        }

        if (! isset(self::COUNTRIES[$region])) {
            return null;
        }

        [$callingCode, $minLen, $maxLen] = self::COUNTRIES[$region];

        // Strip national trunk zeros: +962 0785555555 -> +962785555555.
        $national = ltrim($national, '0');
        if ($national === '' || ! preg_match('/^\d+$/', $national)) {
            return null;
        }

        $length = strlen($national);
        if ($length < $minLen || $length > $maxLen) {
            return null;
        }

        // Jordan mobile numbers are exactly 9 digits after +962 starting with 7.
        if ($region === 'JO' && preg_match('/^7\d{8}$/', $national) !== 1) {
            return null;
        }

        return '+'.$callingCode.$national;
    }

    public static function isValid(?string $phone, string $defaultRegion = 'JO'): bool
    {
        return self::normalize($phone, $defaultRegion) !== null;
    }

    /**
     * Calling codes sorted longest-first so +966 never matches +96.
     */
    private static function callingCodes(): array
    {
        static $sorted = null;

        if ($sorted === null) {
            $sorted = [];
            foreach (self::COUNTRIES as $region => [$code]) {
                $sorted[$code] = $region;
            }
            uksort($sorted, fn ($a, $b) => strlen($b) <=> strlen($a));
        }

        return $sorted;
    }
}
