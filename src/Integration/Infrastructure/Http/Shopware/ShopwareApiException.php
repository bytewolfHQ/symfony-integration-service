<?php

declare(strict_types=1);

namespace App\Integration\Infrastructure\Http\Shopware;

use RuntimeException;

final class ShopwareApiException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $method,
        public readonly string $path,
        public readonly string $snippet = '',
    )
    {
        $msg = sprintf(
            'Shopware API returned error %d for %s %s%s',
            $status,
            strtoupper($method),
            $path,
            $snippet !== '' ? ': ' . $snippet : '',
        );

        parent::__construct($msg);
    }
}