<?php

declare(strict_types=1);

namespace App\Integration\Infrastructure\Shopware\Product;

use App\Integration\Infrastructure\Http\Shopware\ShopwareAdminApiClient;
use App\Integration\Infrastructure\Shopware\ReferenceData\ShopwareReferenceDataResolver;

final class ShopwareProductImportService
{
    public function __construct(
        private ShopwareAdminApiClient $client,
        private ShopwareReferenceDataResolver $resolver,
    ) {}

    /**
     * Returns the product id if found, otherwise null.
     * @param string $productNumber
     * @return string|null
     */
    public function findByProductNumber(string $productNumber): ?string
    {
        $res = $this->client->requestOrFail(
            method: 'POST',
            path: '/api/search/product',
            json: [
                'limit' => 1,
                'filter' => [[
                    'type' => 'equals',
                    'field' => 'productNumber',
                    'value' => $productNumber,
                ]],
                'include' => ['id', 'productNumber', 'translated'],
            ],
            authenticated: true
        );

        $data = $res['body']['data'] ?? null;
        if (!is_array($data) || $data === []) {
            return null;
        }

        $first = $data[0] ?? null;
        if (!is_array($first) || !isset($first['id'])) {
            return null;
        }

        return (string) $first['id'];
    }

    /**
     * Return "create" or "update"
     * @param ProductDraft $draft
     * @param bool $dryRun
     * @return string
     */
    public function upsert(ProductDraft $draft, bool $dryRun = false): string
    {
        $existingId = $this->findByProductNumber($draft->productNumber);

        if ($existingId === null) {
            if ($dryRun) {
                return 'create';
            }

            $this->client->requestOrFail(
                method: 'POST',
                path: '/api/product',
                json: [
                    'name' => $draft->name,
                    'productNumber' => $draft->productNumber,
                    'stock' => 10,
                    'price' => [[
                        'currencyId' => $this->resolver->getCurrencyId(),
                        'gross' => 9.99,
                        'net' => 8.39,
                        'linked' => false,
                    ]],
                    'taxId' => $this->resolver->getTaxId(),
                ],
                authenticated: true
            );

            return 'create';
        }

        if ($dryRun) {
            return 'update';
        }

        $this->client->requestOrFail(
            method: 'PATCH',
            path: '/api/product/'.$existingId,
            json: [
                'name' => $draft->name,
            ],
            authenticated: true
        );

        return 'update';
    }
}