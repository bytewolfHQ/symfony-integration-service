<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Http\Shopware;

use App\Integration\Infrastructure\Http\Shopware\ShopwareTokenProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ShopwareTokenProviderTest extends TestCase
{
    public function test_fetches_and_caches_token(): void
    {
        $calls = 0;

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$calls) {
            $calls++;

            self::assertSame('POST', $method);
            self::assertStringContainsString('/api/oauth/token', $url);
            self::assertTrue(
                isset($options['json']) || isset($options['body']),
                'Expected request options to contain either "json" or "body".'
            );

            $payload = null;

            if (isset($options['json']) && is_array($options['json'])) {
                $payload = $options['json'];
            } elseif (isset($options['body'])) {
                $body = $options['body'];

                // OAuth form-encoded (Symfony HttpClient): body is typically an array
                if (is_array($body)) {
                    $payload = $body;
                } elseif (is_string($body)) {
                    // could be raw json string or query-string
                    $decoded = json_decode($body, true);
                    if (is_array($decoded)) {
                        $payload = $decoded;
                    } else {
                        parse_str($body, $parsed);
                        if (is_array($parsed) && $parsed !== []) {
                            $payload = $parsed;
                        }
                    }
                }
            }

            self::assertIsArray($payload, 'Could not extract request payload from options.');
            self::assertSame('client_credentials', $payload['grant_type'] ?? null);
            self::assertSame('client-id', $payload['client_id'] ?? null);
            self::assertSame('client-secret', $payload['client_secret'] ?? null);

            return new MockResponse(json_encode([
                'access_token' => 'token-123',
                'expires_in' => 3600,
            ]), ['http_code' => 200]);
        });

        $provider = new ShopwareTokenProvider(
            httpClient: $httpClient,
            baseUrl: 'https://shopware.example', // wichtig: ohne /api hier (TokenProvider hängt es an)
            clientId: 'client-id',
            clientSecret: 'client-secret',
            verifySsl: true
        );

        $t1 = $provider->getAccessToken();
        $t2 = $provider->getAccessToken();

        self::assertSame('token-123', $t1);
        self::assertSame('token-123', $t2);
        self::assertSame(1, $calls, 'Token should be cached and only fetched once.');
    }

    public function test_throws_on_non_2xx_response(): void
    {
        $httpClient = new MockHttpClient(function () {
            return new MockResponse('{"errors":[{"detail":"Unauthorized"}]}', ['http_code' => 401]);
        });

        $provider = new ShopwareTokenProvider(
            httpClient: $httpClient,
            baseUrl: 'https://shopware.example',
            clientId: 'client-id',
            clientSecret: 'client-secret',
            verifySsl: true
        );

        $this->expectException(\RuntimeException::class);
        $provider->getAccessToken();
    }
}