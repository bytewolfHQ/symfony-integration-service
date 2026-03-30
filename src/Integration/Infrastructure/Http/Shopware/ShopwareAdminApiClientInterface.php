<?php

declare(strict_types=1);

namespace App\Integration\Infrastructure\Http\Shopware;

interface ShopwareAdminApiClientInterface
{
    /**
     * @param array<string, mixed> $json
     * @param array<string, mixed> $query
     * @param array<string, mixed> $headers
     * @return array{status: int, raw: string, body: array<string, mixed>}
     */
    public function request(
        string $method,
        string $path,
        array $json = [],
        array $query = [],
        array $headers = [],
        bool $authenticated = false,
    ): array;

    /**
     * Like request(), but throws ShopwareApiException on non-2xx status.
     *
     * @param array<string, mixed> $json
     * @param array<string, mixed> $query
     * @param array<string, mixed> $headers
     * @return array{status: int, raw: string, body: array<string, mixed>}
     * @throws ShopwareApiException
     */
    public function requestOrFail(
        string $method,
        string $path,
        array $json = [],
        array $query = [],
        array $headers = [],
        bool $authenticated = false,
    ): array;
}
