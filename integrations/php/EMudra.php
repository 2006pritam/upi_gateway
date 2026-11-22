<?php
/**
 * e-Mudra PHP helper. One file, no dependencies (needs the curl extension).
 *
 *   $api = new EMudra('__GATEWAY_URL__', 'key_...', 'sec_...');
 *   $order = $api->createOrder(499, 'INV1001', ['customer_name' => 'Asha']);
 *   header('Location: '.$order['payment_url']);
 *
 * Webhook endpoint:
 *   $event = $api->verifyWebhook(file_get_contents('php://input'), $_SERVER['HTTP_X_SIGNATURE'] ?? '');
 *   if ($event && $event['event'] === 'order.paid') { mark_paid($event['data']['client_order_id']); }
 */
class EMudra
{
    public function __construct(private string $baseUrl, private string $key, private string $secret) {}

    public function createOrder(float|int|string $amount, string $clientOrderId, array $extra = []): array
    {
        return $this->request('POST', '/api/v1/orders', ['amount' => $amount, 'client_order_id' => $clientOrderId] + $extra);
    }

    public function getOrder(string $orderIdOrClientId): array
    {
        return $this->request('GET', '/api/v1/orders/'.rawurlencode($orderIdOrClientId));
    }

    /** Returns the decoded payload, or null when the signature is wrong. Always check the order status via getOrder() before shipping goods. */
    public function verifyWebhook(string $rawBody, string $signatureHeader): ?array
    {
        $expected = hash_hmac('sha256', $rawBody, $this->secret);

        return hash_equals($expected, $signatureHeader) ? json_decode($rawBody, true) : null;
    }

    private function request(string $method, string $path, ?array $body = null): array
    {
        $ch = curl_init(rtrim($this->baseUrl, '/').$path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', 'X-Api-Key: '.$this->key, 'X-Api-Secret: '.$this->secret],
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $json = is_string($raw) ? json_decode($raw, true) : null;
        if ($code >= 400 || ! is_array($json) || empty($json['success'])) {
            throw new RuntimeException('e-Mudra API error ('.$code.'): '.($json['message'] ?? 'no response'));
        }

        return $json['data'];
    }
}
