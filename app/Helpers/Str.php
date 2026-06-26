<?php
declare(strict_types=1);

namespace App\Helpers;

final class Str
{
    public static function limit(string $text, int $max): string
    {
        if ($max <= 0) {
            return '';
        }
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $max);
        }
        if (strlen($text) <= $max) {
            return $text;
        }
        return substr($text, 0, $max);
    }
}
