<?php
/**
 * GetBD Registrar Module for WiseCP — API Client
 *
 * JSON client for the get.bd partner API (api.get.bd). Ported from the
 * get_bd WHMCS module's GetBDClient, adapted to WiseCP conventions: errors
 * go to $this->error (no exceptions), calls are logged through the $logger
 * closure (wired to RegistrarModule::save_log()).
 *
 * Note: get.bd has no hosted sandbox — its "development" environment is a
 * self-hosted server (see the official API docs). Every request requires
 * the partner API key, so the previous sandbox toggle was removed.
 */

    namespace WISECP\Modules\Registrars\GetBD;

    class ApiClient
    {
        public ?string  $error  = null;
        public ?\Closure $logger = null;
        public int      $timeout = 45;
        public int      $lastHttpCode = 0;

        private string $apiKey;
        private string $baseUrl;

        public const LIVE_BASE = 'https://api.get.bd/api/v1/external';

        public function __construct(array $settings = [])
        {
            $this->apiKey  = trim((string) ($settings['api-key'] ?? $settings['api_key'] ?? ''));
            $this->baseUrl = self::LIVE_BASE;

            if ($this->apiKey === '')
                $this->error = 'Get BD API key is required.';
        }

        public function get(string $uri, array $query = [])
        {
            return $this->request('GET', $uri, $query);
        }

        public function post(string $uri, array $json = [])
        {
            return $this->request('POST', $uri, [], $json);
        }

        public function put(string $uri, array $json = [])
        {
            return $this->request('PUT', $uri, [], $json);
        }

        public function delete(string $uri, array $query = [])
        {
            return $this->request('DELETE', $uri, $query);
        }

        /**
         * Executes a request. Returns the decoded JSON body on any 2xx
         * response (even when the envelope carries success=false — callers
         * inspect the envelope themselves), false on transport/HTTP errors.
         */
        private function request(string $method, string $uri, array $query = [], ?array $json = null)
        {
            $this->error        = null;
            $this->lastHttpCode = 0;

            if ($this->apiKey === '') {
                $this->error = $this->error ?: 'Get BD API key is required.';
                $this->log($method, $uri, $query ?: $json, null, $this->error);
                return false;
            }

            $url     = rtrim($this->baseUrl, '/') . '/' . ltrim($uri, '/');
            $payload = null;

            if ($query) $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);

            $headers = [
                'Accept: application/json',
                'X-API-Key: ' . $this->apiKey,
                'Authorization: Bearer ' . $this->apiKey,
            ];

            if ($json !== null) {
                $payload   = json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $headers[] = 'Content-Type: application/json';
            }

            $ch = curl_init($url);

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_USERAGENT      => 'WISECP-GetBD',
                CURLOPT_ENCODING       => '',
            ]);

            $raw    = curl_exec($ch);
            $errno  = curl_errno($ch);
            $errmsg = curl_error($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

            curl_close($ch);

            $this->lastHttpCode = $status;

            if ($errno) {
                $this->error = 'Curl Error: ' . $errmsg;
                $this->log($method, $uri, $query ?: $json, null, $this->error);
                return false;
            }

            $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

            if (!is_array($decoded)) {
                $this->error = $status >= 400
                    ? 'HTTP ' . $status . ': Get BD API returned an invalid response.'
                    : 'Get BD API returned an invalid JSON response.';
                $this->log($method, $uri, $query ?: $json, $raw, $this->error);
                return false;
            }

            if ($status >= 400) {
                $this->error = (string) ($decoded['message'] ?? ('HTTP ' . $status . ' from Get BD API.'));
                $this->log($method, $uri, $query ?: $json, $raw, $this->error);
                return false;
            }

            $this->log($method, $uri, $query ?: $json, $raw, '');

            return $decoded;
        }

        private function log(string $method, string $uri, mixed $request, mixed $response, string $processed = ''): void
        {
            if (!$this->logger) return;

            $logRequest = $request;
            if (is_array($logRequest)) {
                unset($logRequest['api-key'], $logRequest['apiKey']);
            }

            ($this->logger)(
                $method . ' ' . $uri,
                $logRequest,
                is_string($response) ? htmlentities((string) $response) : $response,
                $processed
            );
        }
    }
