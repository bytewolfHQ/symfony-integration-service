<?php

declare(strict_types=1);

namespace App\Integration\Infrastructure\Http\Shopware;

interface ShopwareTokenProviderInterface
{
    public function getAccessToken(): string;
}
