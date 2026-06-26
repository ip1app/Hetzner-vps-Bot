<?php
declare(strict_types=1);

namespace App\Helpers;

final class Html
{
    public static function esc(mixed $v): string
    {
        return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
