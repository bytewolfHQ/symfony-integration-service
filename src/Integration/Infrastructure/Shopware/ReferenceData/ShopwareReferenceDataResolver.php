<?php

declare(strict_types=1);

namespace App\Integration\Infrastructure\Shopware\ReferenceData;

use App\Integration\Infrastructure\Http\Shopware\ShopwareAdminApiClient;

final class ShopwareReferenceDataResolver implements ShopwareReferenceDataResolverInterface
{
    // Cache keyed by iso code, e.g. ['EUR' => 'uuid...']
    private array $currencyIds = [];

    // Cache keyed by tax rate, e.g. [19 => 'uuid...', 7 => 'uuid...']
    private array $taxIds = [];

    public function __construct(
        private readonly ShopwareAdminApiClient $client,
    ) {}

    public function getCurrencyId(?string $currency = null): string
    {
        // Fall back to EUR if no currency given
        $isoCode = $currency ?? 'EUR';

        // Return from cache if already resolved
        if (isset($this->currencyIds[$isoCode])) {
            return $this->currencyIds[$isoCode];
        }

        $res = $this->client->requestOrFail(
            method: 'POST',
            path: '/api/search/currency',
            json: [
                'limit' => 1,
                'filter' => [[
                    'type'  => 'equals',
                    'field' => 'isoCode',
                    'value' => $isoCode,
                ]],
                'includes' => ['currency' => ['id', 'isoCode']],
            ],
            authenticated: true
        );

        $id = $res['body']['data'][0]['id'] ?? null;
        if (!is_string($id) || $id === '') {
            throw new \RuntimeException(sprintf('Could not resolve currencyId for "%s".', $isoCode));
        }

        return $this->currencyIds[$isoCode] = $id;
    }

    public function getTaxId(int $taxRate = 19): string
    {
        // Return from cache if already resolved
        if (isset($this->taxIds[$taxRate])) {
            return $this->taxIds[$taxRate];
        }

        $res = $this->client->requestOrFail(
            method: 'POST',
            path: '/api/search/tax',
            json: [
                'limit' => 1,
                'filter' => [[
                    'type'  => 'equals',
                    'field' => 'taxRate',
                    'value' => $taxRate,
                ]],
                'includes' => ['tax' => ['id', 'taxRate']],
            ],
            authenticated: true
        );

        $id = $res['body']['data'][0]['id'] ?? null;
        if (!is_string($id) || $id === '') {
            throw new \RuntimeException(sprintf('Could not resolve taxId for taxRate=%d.', $taxRate));
        }

        return $this->taxIds[$taxRate] = $id;
    }
}