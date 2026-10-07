<?php

namespace App\Household;

final class InviteCode
{
    const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    public static function generate()
    {
        $code = '';
        $alphabet = self::ALPHABET;
        $lastIndex = strlen($alphabet) - 1;
        for ($index = 0; $index < 8; $index++) {
            $code .= $alphabet[ord(\App\Support\Compat::randomBytes(1)) & $lastIndex];
        }
        return $code;
    }
    public static function normalize($code)
    {
        return strtoupper(trim($code));
    }
    public static function isValid($code)
    {
        return preg_match('/^[A-HJ-NP-Z2-9]{8}$/', $code) === 1;
    }
    public static function hash($code)
    {
        return hash('sha256', self::normalize($code));
    }
}
