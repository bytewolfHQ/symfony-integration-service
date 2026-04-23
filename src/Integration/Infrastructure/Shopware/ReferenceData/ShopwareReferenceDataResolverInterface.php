<?php

declare(strict_types=1);

namespace App\Integration\Infrastructure\Shopware\ReferenceData;

interface ShopwareReferenceDataResolverInterface
{
    public function getCurrencyId(?string $currency = null): string;
    public function getTaxId(int $taxRate = 19): string;

    public function getManufacturerId(string $manufacturerName): string;
}