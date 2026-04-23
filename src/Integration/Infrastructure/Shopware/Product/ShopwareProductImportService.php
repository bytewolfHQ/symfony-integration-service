<?php

declare(strict_types=1);

namespace App\Integration\Infrastructure\Shopware\Product;

use App\Integration\Domain\ProductDraft;
use App\Integration\Infrastructure\Http\Shopware\ShopwareAdminApiClientInterface;
use App\Integration\Infrastructure\Shopware\ReferenceData\ShopwareReferenceDataResolverInterface;

final class ShopwareProductImportService implements ShopwareProductImportInterface
{
    private const DEFAULT_STOCK = 0;

    public function __construct(
        private readonly ShopwareAdminApiClientInterface $client,
        private readonly ShopwareReferenceDataResolverInterface $resolver,
        // Configurable via services.yaml: $defaultTaxRate: '%integration.adapters.shopware.default_tax_rate%'
        private readonly int $defaultTaxRate = 19,
    ) {}

    /**
     * Returns the product id if found, otherwise null.
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
     * Returns "create", "update", or "skipped".
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

        // fetch current data and compare — skip if nothing changed
        $current = $this->fetchCurrentData($existingId);
        if (!$this->hasChanges($draft, $current)) {
            return 'skipped';
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
     * - taxRate falls back to $defaultTaxRate for net calculation and taxId resolution
     * - active defaults to true if not set
     *
     * @return array<string, mixed>
     */
    private function buildPayload(ProductDraft $draft): array
    {
        // Use taxRate from draft or fallback to default (e.g. 19%)
        $effectiveTaxRate = $draft->taxRate ?? $this->defaultTaxRate;

        $payload = [
            'name'          => $draft->name,
            'productNumber' => $draft->productNumber,
            'description'   => $draft->description,
            'stock'         => $draft->stock ?? self::DEFAULT_STOCK,
            'active'        => $draft->active ?? true,
            // Cast to int: taxRate is ?float in ProductDraft, getTaxId() expects int
            'taxId'         => $this->resolver->getTaxId((int) $effectiveTaxRate),
        ];

        if ($draft->manufacturer !== null) {
            $payload['manufacturerId'] = $this->resolver->getManufacturerId($draft->manufacturer);
        }

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

    /**
     * Fetches the fields we care about for comparison.
     * Values are cast to their expected PHP types to guard against APIs
     * returning numbers as strings (e.g. stock as "0").
     *
     * @return array{name: string|null, manufacturer: string|null, description: string|null, stock: int|null, active: bool|null, gross: float|null}
     */
    private function fetchCurrentData(string $productId): array
    {
        $res = $this->client->requestOrFail(
            method: 'GET',
            path: '/api/product/' . $productId,
            json: [],
            authenticated: true
        );

        $data = $res['body']['data']['attributes'] ?? [];

        return [
            'name'   => is_string($data['name'] ?? null) ? $data['name'] : null,
            'manufacturer' => is_string($data['manufacturer'] ?? null) ? $data['manufacturer'] : null,
            'description' => is_string($data['description'] ?? null) ? $data['description'] : null,
            'stock'  => isset($data['stock']) ? (int) $data['stock'] : null,
            'active' => isset($data['active']) ? (bool) $data['active'] : null,
            // First price entry, default currency
            'gross'  => isset($data['price'][0]['gross']) ? (float) $data['price'][0]['gross'] : null,
        ];
    }

    /**
     * Compares draft against current Shopware data.
     *
     * @param array{name: string|null, manufacturer: string|null, description: string|null, stock: int|null, active: bool|null, gross: float|null} $current
     */
    private function hasChanges(ProductDraft $draft, array $current): bool
    {
        // Compare name
        if ($draft->name !== $current['name']) {
            return true;
        }

        // Compare manufacturer
        if ($draft->manufacturer !== $current['manufacturer']) {
            return true;
        }

        // Compare description
        if ($draft->description !== $current['description']) {
            return true;
        }

        // Compare stock — draft fallback to DEFAULT_STOCK
        $draftStock = $draft->stock ?? self::DEFAULT_STOCK;
        if ($draftStock !== $current['stock']) {
            return true;
        }

        // Compare active — draft fallback to true
        $draftActive = $draft->active ?? true;
        if ($draftActive !== $current['active']) {
            return true;
        }

        // Compare gross — only if draft has a price
        if ($draft->gross !== null) {
            $currentGross = $current['gross'];
            // Float comparison with small tolerance to avoid floating point issues
            if ($currentGross === null || abs($draft->gross - $currentGross) > 0.001) {
                return true;
            }
        }

        return false;
    }
}
