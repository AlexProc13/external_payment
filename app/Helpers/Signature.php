<?php

namespace App\Helpers;

class Signature
{
    public static function make(string $data, string $apiToken): string
    {
        return hash_hmac('sha256', $data, $apiToken);
    }

    public static function isValid(string $sign, string $data, string $apiToken): bool
    {
        return hash_equals($sign, self::make($data, $apiToken));
    }

    public static function makeBySha512(string $data, string $apiToken): string
    {
        return hash_hmac('sha512', $data, $apiToken);
    }
}
