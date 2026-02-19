<?php

declare(strict_types=1);

namespace App\Integration\Infrastructure\Http\Shopware;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ShopwareAdminApiClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly ShopwareTokenProviderInterface $tokenProvider,
        private readonly string $baseUrl,
        private readonly bool $verifySsl = true, // stays true; you fixed CA trust
    ) {
    }

    /**
     * Genral request entry point (M0.3)
     *
     * @param array<string, mixed> $json
     * @param array<string, scalar> $query
     * @param array<string, string> $headers
     * @return array{status:int, body:string}
     */
    public function request(
        string $method,
        string $path,
        array $json = [],
        array $query = [],
        array $headers = [],
        bool $authenticated = false,
    ): array
    {
        $url = $this->buildUrl($path);

        $options = [
            'verify_peer' => $this->verifySsl,
            'verify_host' => $this->verifySsl,
        ];

        if ($query !== []) {
            $options['query'] = $query;
        }

        // Only set json option if we actually have a body.
        // (GET normally has no body, POST/PUT/PATCH typically do.)
        if ($json !== []) {
            $options['json'] = $json;
        }

        $this->logger->info('Shopware Admin API request', [
            'method' => $method,
            'path' => $path,
        ]);

        if ($authenticated) {
            $token = $this->tokenProvider->getAccessToken();
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        if ($headers !== []) {
            $options['headers'] = $headers;
        }

        $response = $this->httpClient->request($method, $url, $options);

        $status = $response->getStatusCode();

        $this->logger->info('Shopware Admin API response', [
            'method' => $method,
            'path' => $path,
            'status' => $status,
        ]);

        $raw = $response->getContent(false);

        $decoded = null;
        if (is_string($raw) && $raw !== '') {
            $tmp = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) {
                $decoded = $tmp;
            }
        }

        return [
            'status' => $status,
            'raw' => $raw,
            'body' => $decoded ?? ['raw' => $raw],
        ];
    }

    public function requestOrFail(
        string $method,
        string $path,
        array $json = [],
        array $query = [],
        array $headers = [],
        bool $authenticated = false,
    ): array
    {
        $res = $this->request($method, $path, $json, $query, $headers, $authenticated);
        
        if ($res['status'] >= 200 && $res['status'] < 300) {
            return $res;
        }
        
        $snippet = $this->snippet($res['body']);
        throw new ShopwareApiException($res['status'], $method, $path, $snippet);
    }

    /**
     * Convenience wrapper for GET.
     *
     * @return array{status:int, body:string}
     */
    public function get(string $path, array $query = [], array $headers = [], bool $authenticated = false): array
    {
        return $this->request('GET', $path, [], $query, $headers, $authenticated);
    }

    private function buildUrl(string $path): string
    {
        return rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
    }

    private function snippet(string $text, int $max = 300): string
    {
        $t = trim($text);
        if ($t === '') {
            return '';
        }

        if (mb_strlen($t) <= $max) {
            return $t;
        }

        return mb_substr($t, 0, $max) . '…';
    }
}
