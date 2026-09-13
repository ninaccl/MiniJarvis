<?php

declare(strict_types=1);

namespace App\Household;

final class InviteCode
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public static function generate(): string
    {
        $code = '';
        $lastIndex = strlen(self::ALPHABET) - 1;
        for ($index = 0; $index < 8; $index++) {
            $code .= self::ALPHABET[random_int(0, $lastIndex)];
        }
        return $code;
    }

    public static function normalize(string $code): string
    {
        return strtoupper(trim($code));
    }

    public static function isValid(string $code): bool
    {
        return preg_match('/^[A-HJ-NP-Z2-9]{8}$/', $code) === 1;
    }

    public static function hash(string $code): string
    {
        return hash('sha256', self::normalize($code));
    }
}
