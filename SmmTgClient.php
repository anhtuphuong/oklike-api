<?php

/**
 * Client gọi tới SMM-TG (backend thực tế).
 * Tất cả phương thức trả về array đã json_decode (true).
 * Ném SmmTgException khi SMM-TG trả lỗi (ok:false) hoặc lỗi mạng.
 */
class SmmTgClient
{
    private string $baseUrl;
    private string $apiKey;

    public function __construct(string $baseUrl, string $apiKey)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey  = $apiKey;
    }

    public function health(): array
    {
        return $this->request('GET', '/health', null, false);
    }

    public function account(): array
    {
        return $this->request('GET', '/account');
    }

    public function pricing(): array
    {
        return $this->request('GET', '/pricing');
    }

    /**
     * @param array $links [['link'=>..., 'qty'=>...], ...] hoặc single link/qty
     */
    public function placeOrder(array $body): array
    {
        return $this->request('POST', '/orders', $body);
    }

    public function orderStatus(string $orderId): array
    {
        return $this->request('GET', '/orders/' . rawurlencode($orderId));
    }

    /**
     * @throws SmmTgException
     */
    private function request(string $method, string $path, ?array $body = null, bool $auth = true): array
    {
        $url = $this->baseUrl . $path;
        $ch  = curl_init($url);

        $headers = ['Content-Type: application/json'];
        if ($auth) {
            $headers[] = 'X-API-Key: ' . $this->apiKey;
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body ?? []));
        }

        $raw     = curl_exec($ch);
        $errno   = curl_errno($ch);
        $errmsg  = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $retryAfter = curl_getinfo($ch, CURLINFO_HEADER_OUT); // not used, placeholder
        curl_close($ch);

        if ($errno !== 0 || $raw === false) {
            throw new SmmTgException('connection_error', $errmsg, 0);
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new SmmTgException('bad_response', 'Invalid JSON from SMM-TG', $httpCode);
        }

        if (($data['ok'] ?? false) !== true) {
            $code  = $data['error'] ?? 'unknown_error';
            $detail = $data['detail'] ?? null;
            throw new SmmTgException($code, is_array($detail) ? json_encode($detail) : (string)$detail, $httpCode, $data);
        }

        return $data;
    }
}

class SmmTgException extends Exception
{
    public string $errorCode;
    public ?array $raw;
    public int $httpCode;

    public function __construct(string $errorCode, ?string $detail, int $httpCode = 0, ?array $raw = null)
    {
        $this->errorCode = $errorCode;
        $this->httpCode  = $httpCode;
        $this->raw       = $raw;
        parent::__construct($errorCode . ($detail ? (": $detail") : ''));
    }
}
