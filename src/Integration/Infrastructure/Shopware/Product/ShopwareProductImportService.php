<?php

declare(strict_types=1);

namespace App\Integration\Infrastructure\Shopware\Product;

use App\Integration\Infrastructure\Http\Shopware\ShopwareAdminApiClient;

final class ShopwareProductImportService
{
    private ?string $currencyId = null;
    private ?string $taxId = null;

    public function __construct(
        private ShopwareAdminApiClient $client,
    ) {}

    /**
     * Returns the product id if found, otherwise null.
     * @param string $productNumber
     * @return array|null
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
                        'currencyId' => $this->getCurrencyId(),
                        'gross' => 9.99,
                        'net' => 8.39,
                        'linked' => false,
                    ]],
                    'taxId' => $this->getTaxId(),
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

    private function getCurrencyId(): string
    {
        if ($this->currencyId !== null) {
            return $this->currencyId;
        }

        // Minimal: find EUR currency id
        $res = $this->client->requestOrFail(
            method: 'POST',
            path: '/api/search/currency',
            json: [
                'limit' => 1,
                'filter' => [[
                    'type' => 'equals',
                    'field' => 'isoCode',
                    'value' => 'EUR',
                ]],
                'includes' => [
                    'currency' => ['id', 'isoCode'],
                ],
            ],
            authenticated: true
        );

        $id = $res['body']['data'][0]['id'] ?? null;
        if (!is_string($id) || $id === '') {
            throw new \RuntimeException('Could not resolve currencyId for EUR.');
        }

        return $this->currencyId = $id;
    }

    private function getTaxId(): string
    {
        if ($this->taxId !== null) {
            return $this->taxId;
        }

        // Minimal: first tax with taxRate 19
        $res = $this->client->requestOrFail(
            method: 'POST',
            path: '/api/search/tax',
            json: [
                'limit' => 1,
                'filter' => [[
                    'type' => 'equals',
                    'field' => 'taxRate',
                    'value' => 19,
                ]],
                'includes' => [
                    'tax' => ['id', 'taxRate'],
                ],
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