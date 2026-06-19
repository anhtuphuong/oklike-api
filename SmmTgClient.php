<?php

declare(strict_types=1);

final class SmmTgException extends RuntimeException
{
    private ?array $response;

    public function __construct(string $message, int $code = 0, ?Throwable $previous = null, ?array $response = null)
    {
        parent::__construct($message, $code, $previous);
        $this->response = $response;
    }

    public function getResponse(): ?array
    {
        return $this->response;
    }
}

final class SmmTgClient
{
    private string $baseUrl;
    private string $apiKey;
    private int $timeout;

    public function __construct(array $config)
    {
        $this->baseUrl = rtrim((string)($config['base_url'] ?? 'https://api.smm-tg.net'), '/');
        $this->apiKey = (string)($config['api_key'] ?? '');
        $this->timeout = (int)($config['timeout'] ?? 20);

        if ($this->apiKey === '') {
            throw new SmmTgException('SMM-TG API key is missing in config.');
        }
    }

    public function getPricing(): array
    {
        return $this->request('GET', '/pricing');
    }

    public function createOrder(int $serviceId, string $link, int $quantity, int $timeLeave): string
    {
        $response = $this->request('POST', '/orders', [
            'service_id' => $serviceId,
            'links' => [$link],
            'quantity' => $quantity,
            'time_leave' => $timeLeave,
        ]);

        $id = $response['order_id'] ?? $response['id'] ?? $response['order'] ?? null;
        if ($id === null || $id === '') {
            throw new SmmTgException('SMM-TG response missing order ID.', 0, null, $response);
        }

        return (string)$id;
    }

    public function getOrder(string $orderId): array
    {
        return $this->request('GET', '/orders/' . rawurlencode($orderId));
    }

    public function getAccount(): array
    {
        return $this->request('GET', '/account');
    }

    private function request(string $method, string $path, ?array $payload = null): array
    {
        $url = $this->baseUrl . $path;

        if ($method === 'GET' && $payload !== null && $payload !== []) {
            $url .= '?' . http_build_query($payload);
        }

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'X-API-Key: ' . $this->apiKey,
        ];

        $ch = curl_init($url);
        if ($ch === false) {
            throw new SmmTgException('Failed to initialize cURL.');
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $this->timeout,
        ];

        if ($method !== 'GET' && $payload !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        curl_setopt_array($ch, $options);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new SmmTgException('SMM-TG request failed: ' . $error);
        }

        if (!is_string($raw) || $raw === '') {
            throw new SmmTgException('SMM-TG returned empty response.');
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new SmmTgException('SMM-TG returned invalid JSON: ' . $raw);
        }

        if ($httpCode >= 400) {
            $message = (string)($decoded['message'] ?? $decoded['error'] ?? ('SMM-TG HTTP error ' . $httpCode));
            throw new SmmTgException($message, $httpCode, null, $decoded);
        }

        if (($decoded['success'] ?? true) === false) {
            $message = (string)($decoded['message'] ?? $decoded['error'] ?? 'SMM-TG API error.');
            throw new SmmTgException($message, 0, null, $decoded);
        }

        return $decoded;
    }
}
