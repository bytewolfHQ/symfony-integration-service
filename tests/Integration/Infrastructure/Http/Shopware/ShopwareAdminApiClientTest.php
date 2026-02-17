<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Http\Shopware;

use App\Integration\Infrastructure\Http\Shopware\ShopwareAdminApiClient;
use App\Integration\Infrastructure\Http\Shopware\ShopwareApiException;
use App\Integration\Infrastructure\Http\Shopware\ShopwareTokenProviderInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ShopwareAdminApiClientTest extends TestCase
{
    public function test_request_or_fail_throws_exception_on_non_2xx(): void
    {
        $httpClient = new MockHttpClient(function () {
            return new MockResponse('{"errors":[{"detail":"Not Found"}]}', ['http_code' => 404]);
        });

        $tokenProvider = $this->createStub(ShopwareTokenProviderInterface::class);
        $tokenProvider->method('getAccessToken')->willReturn('token-xyz');

        $client = new ShopwareAdminApiClient(
            httpClient: $httpClient,
            logger: new NullLogger(),
            tokenProvider: $tokenProvider,
            baseUrl: 'https://shopware.example',
            verifySsl: true
        );

        $this->expectException(ShopwareApiException::class);

        $client->requestOrFail(
            method: 'GET',
            path: '/api/does-not-exist',
            authenticated: false
        );
    }

    public function test_authenticated_request_adds_bearer_header(): void
    {
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) {
            self::assertSame('GET', $method);

            $headers = $options['headers'] ?? [];
            $auth = null;
            if (is_array($headers) && isset($headers['Authorization'])) {
                $auth = $headers['Authorization'];
            }
            if ($auth === null && is_array($headers)) {
                foreach ($headers as $h) {
                    if (is_string($h) && stripos($h, 'Authorization:') === 0) {
                        $auth = trim(substr($h, strlen('Authorization:')));
                        break;
                    }
                }
            }

            self::assertSame('Bearer token-xyz', $auth);

            return new MockResponse('{"ok":true}', ['http_code' => 200]);
        });

        $tokenProvider = $this->createStub(ShopwareTokenProviderInterface::class);
        $tokenProvider->method('getAccessToken')->willReturn('token-xyz');

        $client = new ShopwareAdminApiClient(
            httpClient: $httpClient,
            logger: new NullLogger(),
            tokenProvider: $tokenProvider,
            baseUrl: 'https://shopware.example',
            verifySsl: true
        );

        $res = $client->request(
            method: 'GET',
            path: '/api/_info/version',
            authenticated: true
        );

        self::assertSame(200, $res['status']);
    }
}
