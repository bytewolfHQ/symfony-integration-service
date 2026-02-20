<?php

declare(strict_types=1);

namespace App\Integration\Infrastructure\Shopware\ReferenceData;

use App\Integration\Infrastructure\Http\Shopware\ShopwareAdminApiClient;

final class ShopwareReferenceDataResolver
{
    private ?string $currencyId = null;
    private ?string $taxId = null;

    public function __construct(
        private readonly ShopwareAdminApiClient $client,
    ) {}

    public function getCurrencyId(?string $currency = null): ?string
    {
        if ($this->currencyId !== null) {
            return $this->currencyId;
        }

        if ($currency === null) {
            $currency = 'EUR';
        }

        $res = $this->client->requestOrFail(
            method: 'POST',
            path: '/api/search/currency',
            json: [
                'limit' => 1,
                'filter' => [[
                    'type' => 'equals',
                    'field' => 'isoCode',
                    'value' => $currency,
                ]],
                'includes' => ['currency' => ['id', 'isoCode']],
            ],
            authenticated: true
        );

        $id = $res['body']['data'][0]['id'] ?? null;
        if (!is_string($id) || $id === '') {
            throw new \RuntimeException('Could not resolve currencyId for EUR.');
        }

        return $this->currencyId = $id;
    }

    public function getTaxId(int $taxId = 19): ?string
    {
        if ($this->taxId !== null) {
            return $this->taxId;
        }

        $res = $this->client->requestOrFail(
            method: 'POST',
            path: '/api/search/tax',
            json: [
                'limit' => 1,
                'filter' => [[
                    'type' => 'equals',
                    'field' => 'taxRate',
                    'value' => $taxId,
                ]],
                'includes' => ['tax' => ['id', 'taxRate']],
            ],
            authenticated: true
        );

        $id = $res['body']['data'][0]['id'] ?? null;
        if (!is_string($id) || $id === '') {
            throw new \RuntimeException('Could not resolve taxId for taxRate=19.');
        }

        return $this->taxId = $id;
    }
}