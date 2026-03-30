<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Shopware\ReferenceData;

use App\Integration\Infrastructure\Http\Shopware\ShopwareAdminApiClientInterface;
use App\Integration\Infrastructure\Shopware\ReferenceData\ShopwareReferenceDataResolver;
use PHPUnit\Framework\TestCase;

final class ShopwareReferenceDataResolverTest extends TestCase
{
    public function test_getCurrencyId_resolves_uuid_from_api(): void
    {
        $client = $this->createMock(ShopwareAdminApiClientInterface::class);
        $client
            ->expects(self::once())
            ->method('requestOrFail')
            ->willReturn(['status' => 200, 'raw' => '', 'body' => ['data' => [['id' => 'eur-uuid-123']]]]);

        $id = (new ShopwareReferenceDataResolver($client))->getCurrencyId('EUR');

        self::assertSame('eur-uuid-123', $id);
    }

    public function test_getCurrencyId_caches_result(): void
    {
        // API is called only once despite two getCurrencyId() invocations
        $client = $this->createMock(ShopwareAdminApiClientInterface::class);
        $client
            ->expects(self::once())
            ->method('requestOrFail')
            ->willReturn(['status' => 200, 'raw' => '', 'body' => ['data' => [['id' => 'eur-uuid-123']]]]);

        $resolver = new ShopwareReferenceDataResolver($client);
        $resolver->getCurrencyId('EUR');
        $id = $resolver->getCurrencyId('EUR');

        self::assertSame('eur-uuid-123', $id);
    }

    public function test_getCurrencyId_defaults_to_EUR(): void
    {
        $capturedJson = null;
        $client = $this->createMock(ShopwareAdminApiClientInterface::class);
        $client
            ->expects(self::once())
            ->method('requestOrFail')
            ->willReturnCallback(function (string $method, string $path, array $json) use (&$capturedJson): array {
                $capturedJson = $json;
                return ['status' => 200, 'raw' => '', 'body' => ['data' => [['id' => 'eur-uuid-123']]]];
            });

        (new ShopwareReferenceDataResolver($client))->getCurrencyId(null);

        self::assertSame('EUR', $capturedJson['filter'][0]['value']);
    }

    public function test_getCurrencyId_throws_when_not_found(): void
    {
        $client = $this->createStub(ShopwareAdminApiClientInterface::class);
        $client->method('requestOrFail')
            ->willReturn(['status' => 200, 'raw' => '', 'body' => ['data' => []]]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/currencyId/');

        (new ShopwareReferenceDataResolver($client))->getCurrencyId('XYZ');
    }

    public function test_getTaxId_resolves_uuid_from_api(): void
    {
        $client = $this->createMock(ShopwareAdminApiClientInterface::class);
        $client
            ->expects(self::once())
            ->method('requestOrFail')
            ->willReturn(['status' => 200, 'raw' => '', 'body' => ['data' => [['id' => 'tax-uuid-19']]]]);

        $id = (new ShopwareReferenceDataResolver($client))->getTaxId(19);

        self::assertSame('tax-uuid-19', $id);
    }

    public function test_getTaxId_caches_result(): void
    {
        // API is called only once despite two getTaxId() invocations
        $client = $this->createMock(ShopwareAdminApiClientInterface::class);
        $client
            ->expects(self::once())
            ->method('requestOrFail')
            ->willReturn(['status' => 200, 'raw' => '', 'body' => ['data' => [['id' => 'tax-uuid-19']]]]);

        $resolver = new ShopwareReferenceDataResolver($client);
        $resolver->getTaxId(19);
        $id = $resolver->getTaxId(19);

        self::assertSame('tax-uuid-19', $id);
    }

    public function test_getTaxId_caches_different_rates_independently(): void
    {
        $client = $this->createMock(ShopwareAdminApiClientInterface::class);
        $client
            ->expects(self::exactly(2))
            ->method('requestOrFail')
            ->willReturnOnConsecutiveCalls(
                ['status' => 200, 'raw' => '', 'body' => ['data' => [['id' => 'tax-uuid-19']]]],
                ['status' => 200, 'raw' => '', 'body' => ['data' => [['id' => 'tax-uuid-7']]]],
            );

        $resolver = new ShopwareReferenceDataResolver($client);
        self::assertSame('tax-uuid-19', $resolver->getTaxId(19));
        self::assertSame('tax-uuid-7', $resolver->getTaxId(7));
        // Both should now be cached — no further API calls
        self::assertSame('tax-uuid-19', $resolver->getTaxId(19));
        self::assertSame('tax-uuid-7', $resolver->getTaxId(7));
    }

    public function test_getTaxId_throws_when_not_found(): void
    {
        $client = $this->createStub(ShopwareAdminApiClientInterface::class);
        $client->method('requestOrFail')
            ->willReturn(['status' => 200, 'raw' => '', 'body' => ['data' => []]]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/taxId/');

        (new ShopwareReferenceDataResolver($client))->getTaxId(99);
    }
}
