<?php

declare(strict_types=1);

namespace App\Integration\Infrastructure\Http\Shopware;

final class SnippetFormatter
{
    public static function format(mixed $text, int $max = 300): string
    {
        if (is_array($text)) {
            $text = json_encode($text, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        } elseif (!is_string($text)) {
            $text = (string) $text;
        }

        $t = trim($text);
        if ($t === '') {
            return '';
        }

        if (mb_strlen($t) <= $max) {
            return $t;
        }

        return mb_substr($t, 0, $max) . '…';
    }
}
