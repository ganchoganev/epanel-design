<?php

namespace App\Support;

class CatalogCode
{
    public static function normalize(string $raw): string
    {
        $raw = trim($raw);
        if (preg_match('/^(\d+)\.0+$/', $raw, $match)) {
            $raw = $match[1];
        }
        if (preg_match('/^\d+$/', $raw) && strlen($raw) < 9) {
            return str_pad($raw, 9, '0', STR_PAD_LEFT);
        }

        return $raw;
    }

    public static function looksLike(string $raw): bool
    {
        return (bool) preg_match('/^\d{5,12}$/', $raw);
    }
}
