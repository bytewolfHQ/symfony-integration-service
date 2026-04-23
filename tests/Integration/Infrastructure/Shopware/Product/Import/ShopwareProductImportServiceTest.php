<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Shopware\Product\Import;

use App\Integration\Infrastructure\Http\Shopware\ShopwareAdminApiClientInterface;
use App\Integration\Domain\ProductDraft;
use App\Integration\Infrastructure\Shopware\Product\ShopwareProductImportService;
use App\Integration\Infrastructure\Shopware\ReferenceData\ShopwareReferenceDataResolverInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\Stub;

final class ShopwareProductImportServiceTest extends TestCase
{
    private const CURRENCY_ID = 'currency-uuid-eur';
    private const TAX_ID = 'tax-uuid-19';

    private ShopwareAdminApiClientInterface&Stub $client;
    private ShopwareReferenceDataResolverInterface&Stub $resolver;
    private ShopwareProductImportService $service;

    protected function setUp(): void
    {
        $this->client = $this->createStub(ShopwareAdminApiClientInterface::class);
        $this->resolver = $this->createStub(ShopwareReferenceDataResolverInterface::class);

        $this->resolver->method('getCurrencyId')->willReturn(self::CURRENCY_ID);
        $this->resolver->method('getTaxId')->willReturn(self::TAX_ID);

        $this->service = new ShopwareProductImportService($this->client, $this->resolver);
    }

    public function test_upsert_creates_new_product(): void
    {
        // findByProductNumber returns null → product does not exist yet
        $this->client
            ->method('requestOrFail')
            ->willReturnCallback(function (string $method, string $path): array {
                if ($method === 'POST' && str_contains($path, 'search/product')) {
                    return ['body' => ['data' => []]]; // not found
                }
                return ['body' => []]; // create call
            });

        $draft = new ProductDraft('IMP-NEW', 'New Product', null, null, 5, 19.99, null, 19.0, 'EUR', true);
        $result = $this->service->upsert($draft);

        self::assertSame('create', $result);
    }

    public function test_upsert_updates_existing_product(): void
    {
        $this->client
            ->method('requestOrFail')
            ->willReturnCallback(function (string $method, string $path): array {
                if ($method === 'POST' && str_contains($path, 'search/product')) {
                    return ['body' => ['data' => [['id' => 'existing-uuid']]]];
                }
                return ['body' => []]; // patch call
            });

        $draft = new ProductDraft('IMP-101', 'Updated Product', null, null, 10, 29.99, null, 19.0, 'EUR', true);
        $result = $this->service->upsert($draft);

        self::assertSame('update', $result);
    }

    public function test_dry_run_does_not_call_api_for_create(): void
    {
        // Override with a real mock here — we need to verify call count
        $this->client = $this->createMock(ShopwareAdminApiClientInterface::class);
        $this->client
            ->expects(self::once())
            ->method('requestOrFail')
            ->willReturn(['body' => ['data' => []]]);

        // Rebuild service with the new mock
        $this->service = new ShopwareProductImportService($this->client, $this->resolver);

        $draft = new ProductDraft('IMP-NEW', 'New Product');
        $result = $this->service->upsert($draft, dryRun: true);

        self::assertSame('create', $result);
    }

    public function test_net_is_calculated_from_gross_if_missing(): void
    {
        $capturedPayload = null;

        $this->client
            ->method('requestOrFail')
            ->willReturnCallback(
                function (string $method, string $path, array $json) use (&$capturedPayload): array {
                    if ($method === 'POST' && str_contains($path, 'search/product')) {
                        return ['body' => ['data' => []]]; // not found → create
                    }
                    // This is the create call — capture the payload
                    $capturedPayload = $json;
                    return ['body' => []];
                }
            );

        // gross=9.99, net=null → net should be calculated as 9.99 / 1.19 = 8.3950
        $draft = new ProductDraft('IMP-CALC', 'Calc Product', null, null, null, 9.99, null, 19.0);
        $this->service->upsert($draft);

        self::assertNotNull($capturedPayload);
        self::assertSame(9.99, $capturedPayload['price'][0]['gross']);
        self::assertSame(round(9.99 / 1.19, 4), $capturedPayload['price'][0]['net']);
    }

    public function test_active_defaults_to_true_if_null(): void
    {
        $capturedPayload = null;

        $this->client
            ->method('requestOrFail')
            ->willReturnCallback(
                function (string $method, string $path, array $json) use (&$capturedPayload): array {
                    if ($method === 'POST' && str_contains($path, 'search/product')) {
                        return ['body' => ['data' => []]];
                    }
                    $capturedPayload = $json;
                    return ['body' => []];
                }
            );

        // active=null → should default to true in payload
        $draft = new ProductDraft('IMP-ACTIVE', 'Active Product', null, null, null, 9.99, null, 19.0, null, null);
        $this->service->upsert($draft);

        self::assertTrue($capturedPayload['active']);
    }

    public function test_no_price_entry_if_gross_is_null(): void
    {
        $capturedPayload = null;

        $this->client
            ->method('requestOrFail')
            ->willReturnCallback(
                function (string $method, string $path, array $json) use (&$capturedPayload): array {
                    if ($method === 'POST' && str_contains($path, 'search/product')) {
                        return ['body' => ['data' => []]];
                    }
                    $capturedPayload = $json;
                    return ['body' => []];
                }
            );

        // gross=null → no price array in payload
        $draft = new ProductDraft('IMP-NOPRICE', 'No Price Product');
        $this->service->upsert($draft);

        self::assertArrayNotHasKey('price', $capturedPayload);
    }

    public function test_upsert_returns_skipped_when_nothing_changed(): void
    {
        $this->client
            ->method('requestOrFail')
            ->willReturnCallback(function (string $method, string $path): array {
                if ($method === 'POST' && str_contains($path, 'search/product')) {
                    return ['body' => ['data' => [['id' => 'existing-uuid']]]];
                }
                if ($method === 'GET' && str_contains($path, 'existing-uuid')) {
                    return ['body' => ['data' => ['attributes' => [
                        'name'   => 'Product 101',
                        'stock'  => 10,
                        'active' => true,
                        'price'  => [['gross' => 19.99]],
                    ]]]];
                }
                return ['body' => []];
            });

        // Draft matches current Shopware data exactly → should be skipped
        $draft = new ProductDraft('IMP-101', 'Product 101', null, null, 10, 19.99, null, 19.0, 'EUR', true);
        $result = $this->service->upsert($draft);

        self::assertSame('skipped', $result);
    }

    public function test_upsert_returns_update_when_stock_changed(): void
    {
        $this->client
            ->method('requestOrFail')
            ->willReturnCallback(function (string $method, string $path): array {
                if ($method === 'POST' && str_contains($path, 'search/product')) {
                    return ['body' => ['data' => [['id' => 'existing-uuid']]]];
                }
                if ($method === 'GET' && str_contains($path, 'existing-uuid')) {
                    return ['body' => ['data' => ['attributes' => [
                        'name'   => 'Product 101',
                        'stock'  => 10,  // current stock in Shopware
                        'active' => true,
                        'price'  => [['gross' => 19.99]],
                    ]]]];
                }
                return ['body' => []];
            });

        // stock changed from 10 to 20 → should update
        $draft = new ProductDraft('IMP-101', 'Product 101', null, null, 20, 19.99, null, 19.0, 'EUR', true);
        $result = $this->service->upsert($draft);

        self::assertSame('update', $result);
    }

    public function test_upsert_returns_update_when_active_changed(): void
    {
        $this->client
            ->method('requestOrFail')
            ->willReturnCallback(function (string $method, string $path): array {
                if ($method === 'POST' && str_contains($path, 'search/product')) {
                    return ['body' => ['data' => [['id' => 'existing-uuid']]]];
                }
                if ($method === 'GET' && str_contains($path, 'existing-uuid')) {
                    return ['body' => ['data' => ['attributes' => [
                        'name'   => 'Product 101',
                        'stock'  => 10,
                        'active' => true,  // currently active
                        'price'  => [['gross' => 19.99]],
                    ]]]];
                }
                return ['body' => []];
            });

        // active changed to false → should update
        $draft = new ProductDraft('IMP-101', 'Product 101', null, null, 10, 19.99, null, 19.0, 'EUR', false);
        $result = $this->service->upsert($draft);

        self::assertSame('update', $result);
    }

    public function test_dry_run_returns_skipped_without_api_call(): void
    {
        $client = $this->createMock(ShopwareAdminApiClientInterface::class);

        // Only two calls allowed: search + fetch current data — no PATCH
        $client
            ->expects(self::exactly(2))
            ->method('requestOrFail')
            ->willReturnCallback(function (string $method, string $path): array {
                if ($method === 'POST' && str_contains($path, 'search/product')) {
                    return ['body' => ['data' => [['id' => 'existing-uuid']]]];
                }
                // GET current data
                return ['body' => ['data' => ['attributes' => [
                    'name'   => 'Product 101',
                    'stock'  => 10,
                    'active' => true,
                    'price'  => [['gross' => 19.99]],
                ]]]];
            });

        $service = new ShopwareProductImportService($client, $this->resolver);

        $draft = new ProductDraft('IMP-101', 'Product 101', null, null, 10, 19.99, null, 19.0, 'EUR', true);
        $result = $service->upsert($draft, dryRun: true);

        self::assertSame('skipped', $result);
    }
}