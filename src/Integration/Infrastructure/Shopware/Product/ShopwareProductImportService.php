<?php

declare(strict_types=1);

namespace App\Integration\Infrastructure\Shopware\Product;

use App\Integration\Infrastructure\Http\Shopware\ShopwareAdminApiClientInterface;
use App\Integration\Infrastructure\Shopware\ReferenceData\ShopwareReferenceDataResolverInterface;

final class ShopwareProductImportService implements ShopwareProductImporterInterface
{
    // TODO: make configurable via services.yaml parameter (app.shopware.default_tax_rate)
    private const DEFAULT_STOCK = 0;
    private const DEFAULT_TAX_RATE = 19;

    public function __construct(
        private ShopwareAdminApiClientInterface $client,
        private ShopwareReferenceDataResolverInterface $resolver,
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
            if (!$dryRun) {
                $this->client->requestOrFail(
                    method: 'POST',
                    path: '/api/product',
                    json: $this->buildPayload($draft),
                    authenticated: true
                );
            }
            return 'create';
        }

        if (!$dryRun) {
            $this->client->requestOrFail(
                method: 'PATCH',
                path: '/api/product/' . $existingId,
                json: $this->buildPayload($draft),
                authenticated: true
            );
        }

        return 'update';
    }

    /**
     * Builds the Shopware product payload from a ProductDraft.
     *
     * Pricing strategy:
     * - gross is required for a price entry; if missing, no price is sent
     * - net is computed from gross + taxRate if not explicitly provided
     * - currency falls back to resolver default (EUR)
     * - taxRate falls back to DEFAULT_TAX_RATE for net calculation and taxId resolution
     * - active defaults to true if not set
     */
    private function buildPayload(ProductDraft $draft): array
    {
        // Use taxRate from draft or fallback to default (e.g. 19%)
        $effectiveTaxRate = $draft->taxRate ?? self::DEFAULT_TAX_RATE;

        $payload = [
            'name'          => $draft->name,
            'productNumber' => $draft->productNumber,
            'stock'         => $draft->stock ?? self::DEFAULT_STOCK,
            'active'        => $draft->active ?? true,
            // Cast to int: taxRate is ?float in ProductDraft, getTaxId() expects int
            'taxId'         => $this->resolver->getTaxId((int) $effectiveTaxRate),
        ];

        // Only build price entry if gross is present — Shopware rejects price
        // entries without a gross value
        if ($draft->gross !== null) {
            // Calculate net from gross if not explicitly provided:
            // net = gross / (1 + taxRate / 100), e.g. 9.99 / 1.19 = 8.3950
            $net = $draft->net ?? round($draft->gross / (1 + $effectiveTaxRate / 100), 4);

            $payload['price'] = [[
                // Resolver fetches the UUID for the given currency (e.g. "EUR")
                // null → resolver uses its own default (EUR)
                'currencyId' => $this->resolver->getCurrencyId($draft->currency),
                'gross'      => $draft->gross,
                'net'        => $net,
                // linked: false = gross and net are independent values;
                // linked: true would let Shopware auto-calculate net from gross
                'linked'     => false,
            ]];
        }

        return $payload;
    }
}
