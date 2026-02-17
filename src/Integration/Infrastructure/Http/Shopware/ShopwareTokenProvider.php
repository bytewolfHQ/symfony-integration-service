<?php

declare(strict_types=1);

namespace App\Integration\Infrastructure\Http\Shopware;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ShopwareTokenProvider
{
    private ?string $token = null;
    private int $expiresAt = 0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $baseUrl,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly bool $verifySsl = true,
    ) {
    }

    public function getAccessToken(): string
    {
        // Cached token still valid? (30s buffer)
        if ($this->token !== null && time() < ($this->expiresAt - 30)) {
            return $this->token;
        }

        $url = rtrim($this->baseUrl, '/') . '/api/oauth/token';

        $response = $this->httpClient->request('POST', $url, [
            'verify_peer' => $this->verifySsl,
            'verify_host' => $this->verifySsl,
            'json' => [
                'grant_type' => 'client_credentials',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
            ],
        ]);

        $status = $response->getStatusCode();
        $body = $response->getContent(false);

        if ($status < 200 || $status >= 300) {
            $snippet = trim(mb_substr($body, 0, 300));
            throw new \RuntimeException("Shopware token request
            failed ($status): $snippet");
        }

        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['access_token'], $data['expires_in'])) {
            throw new \RuntimeException('Shopware token response is missing access_token/expires_in');
        }

        $this->token = (string) $data['access_token'];
        $this->expiresAt = time() + (int) $data['expires_in'];

        return $this->token;
    }
}