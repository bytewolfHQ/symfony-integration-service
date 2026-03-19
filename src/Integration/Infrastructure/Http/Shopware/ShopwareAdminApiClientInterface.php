<?php

declare(strict_types=1);

namespace App\Integration\Infrastructure\Http\Shopware;

interface ShopwareAdminApiClientInterface
{
    public function requestOrFail(
        string $method,
        string $path,
        array $json = [],
        array $query = [],
        array $headers = [],
        bool $authenticated = false,
    ): array;
}
