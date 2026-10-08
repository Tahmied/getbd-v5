<?php
/**
 * GetBD · .bd domain registrar module for WiseCP v5
 *
 * HTTP client for the get.bd external API.
 *
 * Production API: https://api.get.bd/api/v1/external (v1) and
 * https://api.get.bd/api/v2/external (v2, additive — extended order fields +
 * Idempotency-Key support). v2 order creation is chosen automatically whenever
 * the payload carries v2-only fields.
 *
 * There is no hosted sandbox; development means a self-hosted API
 * (e.g. http://localhost:4000/api/v1/external) configured via the module's
 * base-url / v2-base-url settings.
 */

    namespace WISECP\Modules\Registrars\GetBD;

    class GetBDApiException extends \RuntimeException {}

    class ApiClient
    {
        private string $v1Base;
        private string $v2Base;
        private string $apiKey;
        private int    $timeout;
        /** @var callable|null function(string $action, string $request, string $response, string $processed): mixed */
        private $logger = null;

        /** Fields only accepted by the v2 create-order endpoint. */
        private const V2_ORDER_FIELDS = ['companyName', 'city', 'state', 'postcode', 'country'];

        public function __construct(array $settings = [])
        {
            $this->apiKey = trim((string) ($settings['api-key'] ?? ''));
            if ($this->apiKey === '') {
                throw new GetBDApiException('GetBD API key is not configured.');
            }

            $this->v1Base = rtrim((string) ($settings['base-url'] ?? ''), '/')
                ?: 'https://api.get.bd/api/v1/external';
            $this->v2Base = rtrim((string) ($settings['v2-base-url'] ?? ''), '/')
                ?: 'https://api.get.bd/api/v2/external';
            $this->timeout = max(5, (int) ($settings['timeout'] ?? 30));
        }

        public function setLogger(?callable $logger): void
        {
            $this->logger = $logger;
        }

        /* -------------------------------------------------- endpoints (v1) */

        public function searchDomain(string $domain): array
        {
            return $this->request('GET', '/domains/search', ['query' => ['domain' => $domain]]);
        }

        public function getDomainInfo(string $domain): array
        {
            return $this->request('GET', '/domains/info', ['query' => ['domain' => $domain]]);
        }

        public function processOrder(string $orderId): array
        {
            return $this->request('POST', '/orders/' . rawurlencode($orderId) . '/process');
        }

        public function renewDomain(string $domain, int $years): array
        {
            return $this->request('POST', '/domains/renew', [
                'json' => ['domain' => $domain, 'years' => $years],
            ]);
        }

        public function updateNameservers(string $domain, array $nameServers): array
        {
            return $this->request('PUT', '/domains/update', [
                'json' => ['domain' => $domain, 'nameServers' => array_values($nameServers)],
            ]);
        }

        /**
         * Upload a document against an order. NOTE: the exact multipart
         * contract of this endpoint is not fully documented in the wild; we
         * send the order reference plus the file. Callers MUST treat failures
         * as non-fatal (documents can still be uploaded via the partner
         * portal) — the registry approval gate is what actually matters.
         */
        public function uploadDocument(string $orderId, string $filePath, string $originalName): array
        {
            if (!is_file($filePath)) {
                throw new GetBDApiException('Document file not found: ' . $filePath);
            }

            return $this->request('POST', '/documents/upload', [
                'multipart' => [
                    ['name' => 'orderId', 'contents' => $orderId],
                    ['name' => 'file', 'contents' => fopen($filePath, 'rb'), 'filename' => $originalName],
                ],
            ]);
        }

        /* -------------------------------------------------- endpoints (v2) */

        /**
         * Create an order. Uses the v2 endpoint (which also honours the
         * Idempotency-Key header) whenever v2-only fields are present,
         * otherwise the plain v1 endpoint.
         */
        public function createOrder(array $payload, ?string $idempotencyKey = null): array
        {
            $hasV2 = false;
            foreach (self::V2_ORDER_FIELDS as $field) {
                if (isset($payload[$field]) && $payload[$field] !== '') {
                    $hasV2 = true;
                    break;
                }
            }

            $headers = [];
            if ($idempotencyKey !== null && $idempotencyKey !== '') {
                $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
            }

            return $this->request('POST', '/orders', [
                'json'    => $payload,
                'headers' => $headers,
            ], $hasV2 ? $this->v2Base : $this->v1Base);
        }

        /* -------------------------------------------------------- internals */

        private function request(string $method, string $uri, array $options = [], ?string $base = null): array
        {
            $url     = rtrim($base ?? $this->v1Base, '/') . $uri;
            $headers = array_merge([
                'Accept: application/json',
                'X-API-Key: ' . $this->apiKey,
                'Authorization: Bearer ' . $this->apiKey,
            ], (array) ($options['headers'] ?? []));

            $body = null;
            if (isset($options['json'])) {
                $body = json_encode($options['json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if ($body === false) {
                    throw new GetBDApiException('Failed to encode request payload.');
                }
                $headers[] = 'Content-Type: application/json';
            }

            if (isset($options['query']) && $options['query']) {
                $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($options['query']);
            }

            $logPayload = isset($options['json'])
                ? $body
                : (isset($options['query']) ? json_encode($options['query']) : ($options['multipart'] ?? [] ? '(multipart)' : ''));

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER     => $headers,
            ]);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            if (isset($options['multipart'])) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $options['multipart']);
                // let curl set the multipart boundary itself
                $headers = array_values(array_filter($headers, fn($h) => stripos($h, 'content-type:') !== 0));
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            }

            $response = curl_exec($ch);
            $errno    = curl_errno($ch);
            $error    = curl_error($ch);
            $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $this->log($method . ' ' . $uri, (string) $logPayload, is_string($response) ? $response : '', 'http ' . $status);

            if ($errno) {
                throw new GetBDApiException('get.bd API connection error: ' . $error);
            }
            if (!is_string($response) || $response === '') {
                throw new GetBDApiException('Empty response from get.bd API (http ' . $status . ').');
            }

            $decoded = json_decode($response, true);
            if (!is_array($decoded)) {
                throw new GetBDApiException('Invalid JSON response from get.bd API (http ' . $status . ').');
            }

            if ($status >= 400) {
                $message = (string) ($decoded['message'] ?? $decoded['error'] ?? ('HTTP ' . $status));
                throw new GetBDApiException($message, $status);
            }

            return $decoded;
        }

        private function log(string $action, string $request, string $response, string $processed = ''): void
        {
            if ($this->logger) {
                try {
                    ($this->logger)($action, $request, $response, $processed);
                } catch (\Throwable $e) {
                    // logging must never break an API call
                }
            }
        }
    }
