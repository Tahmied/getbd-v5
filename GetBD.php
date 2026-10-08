<?php
/**
 * GetBD · .bd domain registrar module for WiseCP v5
 *
 * Flow:
 *   1. Checkout creates the domain service in "inprocess" (displays as pending).
 *      register() makes NO registry call — the get.bd order is only created
 *      after the client submits verification documents.
 *   2. The client area shows a "Verify to Active" button (hook-injected) on
 *      pending .bd domains. It opens a modal that collects registrant details
 *      (NID, contact info) and the required documents for the TLD.
 *   3. Submitting the modal calls the get.bd API: create order (domain gets
 *      reserved for 7 days) → upload documents → process order. The expected
 *      "must have at least 2 APPROVED documents" failure is swallowed — BTCL
 *      review happens out of band.
 *   4. The per-minute cron polls GET /domains/info; once localDomain.isActive
 *      is true it flips the service to active and writes the real expiry into
 *      duedate. Afterwards sync()/renew()/nameservers behave normally.
 */

namespace WISECP\Modules\Registrars;

require_once __DIR__ . DS . 'ApiClient.php';

use WISECP\Modules\Registrars\GetBD\ApiClient;
use WISECP\Modules\Registrars\GetBD\GetBDApiException;
use RegistrarModule;

class GetBD extends RegistrarModule
{
    private const PROCESS_DOC_GATE_SNIPPET = 'APPROVED documents';

    /**
     * File types accepted by get.bd /documents/upload (images except SVG,
     * PDF, DOC/DOCX — max 5 MB, content inspected against declared type).
     */
    private const DOC_EXTENSIONS = ['jpg', 'jpeg', 'png', 'bmp', 'webp', 'gif', 'pdf', 'doc', 'docx'];

    /**
     * Per-extension document requirements (official BTCL/get.bd table).
     * get.bd's documentType enum is NID | TRADE_LICENSE | PASSPORT | OTHER
     * (one document per type per order), so:
     *   .bd / .id.bd              -> NID required, passport optional
     *   .com.bd / .co.bd          -> NID + trade licence (both required)
     *   .net/.info/.ai/.tv/.বাংলা -> NID required, trade licence optional
     *   .org.bd                   -> NID + registration certificate (OTHER)
     *   .edu.bd                   -> NID + EIIN/UGC approval (OTHER)
     *   .sch.bd                   -> NID + EIIN certificate (OTHER)
     */
    public static function docSlots(string $tld): array
    {
        $tld = strtolower(trim($tld, '.'));
        if ($tld === '')
            $tld = 'bd';
        if (str_starts_with($tld, 'xn--')) {
            $utf8 = idn_to_utf8($tld, 0, INTL_IDNA_VARIANT_UTS46);
            if (is_string($utf8) && $utf8 !== '')
                $tld = strtolower($utf8);
        }

        $nid = ['input' => 'doc_nid', 'documentType' => 'NID', 'required' => true, 'label' => 'NID document'];
        $passport = ['input' => 'doc_passport', 'documentType' => 'PASSPORT', 'required' => false, 'label' => 'Passport copy (optional)'];

        switch ($tld) {
            case 'com.bd':
            case 'co.bd':
                return [$nid, ['input' => 'doc_secondary', 'documentType' => 'TRADE_LICENSE', 'required' => true, 'label' => 'Trade licence document']];

            case 'org.bd':
                return [$nid, ['input' => 'doc_secondary', 'documentType' => 'OTHER', 'required' => true, 'label' => 'Registration certificate']];

            case 'edu.bd':
                return [$nid, ['input' => 'doc_secondary', 'documentType' => 'OTHER', 'required' => true, 'label' => 'EIIN or UGC approval document']];

            case 'sch.bd':
                return [$nid, ['input' => 'doc_secondary', 'documentType' => 'OTHER', 'required' => true, 'label' => 'EIIN certificate']];

            case 'bd':
            case 'id.bd':
                return [$nid, $passport]; // NID or passport

            case 'net.bd':
            case 'info.bd':
            case 'ai.bd':
            case 'tv.bd':
            case 'বাংলা':
            default: // safe default for anything unknown
                return [$nid, ['input' => 'doc_secondary', 'documentType' => 'TRADE_LICENSE', 'required' => false, 'label' => 'Trade licence document (optional)']];
        }
    }

    /** Addons bridge (client-area endpoint) module name + shipped files. */
    private const BRIDGE_DIR = 'GetBDVerify';
    private const BRIDGE_FILES = ['GetBDVerify.php', 'config.php'];

    /** Guard so a response is only emitted once (test seam re-throws). */
    protected bool $responded = false;

    /* ========================================================== config */

    /**
     * Re-provision the Addons bridge whenever settings are saved.
     */
    public function controller_settings($extraFields = []): array
    {
        self::ensureAddonBridge();
        return parent::controller_settings($extraFields);
    }

    public function config_fields($settings = []): array
    {
        return [
            'api-key' => [
                'type' => 'password',
                'name' => $this->lang['fields']['api-key'] ?? 'API Key',
                'description' => $this->lang['fields']['api-key-desc'] ?? 'Your get.bd partner API key.',
                'value' => $settings['api-key'] ?? '',
            ],
            'test-mode' => [
                'type' => 'approval',
                'name' => $this->lang['fields']['test-mode'] ?? 'Development Mode',
                'description' => $this->lang['fields']['test-mode-desc'] ?? 'Point the module at a self-hosted get.bd development API instead of production.',
                'value' => 1,
                'checked' => (bool) ($settings['test-mode'] ?? false),
            ],
            'base-url' => [
                'type' => 'text',
                'name' => $this->lang['fields']['base-url'] ?? 'API Base URL (v1)',
                'description' => $this->lang['fields']['base-url-desc'] ?? 'Advanced. Default: https://api.get.bd/api/v1/external — use http://localhost:4000/api/v1/external for local development.',
                'value' => $settings['base-url'] ?? '',
            ],
            'v2-base-url' => [
                'type' => 'text',
                'name' => $this->lang['fields']['v2-base-url'] ?? 'API Base URL (v2)',
                'description' => $this->lang['fields']['v2-base-url-desc'] ?? 'Advanced. Default: https://api.get.bd/api/v2/external.',
                'value' => $settings['v2-base-url'] ?? '',
            ],
            'send-v2-fields' => [
                'type' => 'approval',
                'name' => $this->lang['fields']['send-v2-fields'] ?? 'Send extended (v2) order fields',
                'description' => $this->lang['fields']['send-v2-fields-desc'] ?? 'Include companyName / city / state / postcode / country in the create-order payload when provided.',
                'value' => 1,
                'checked' => (bool) ($settings['send-v2-fields'] ?? true),
            ],
            'default-country' => [
                'type' => 'text',
                'name' => $this->lang['fields']['default-country'] ?? 'Default country (ISO 2)',
                'description' => $this->lang['fields']['default-country-desc'] ?? 'Used for the v2 "country" field when the client does not provide one.',
                'value' => $settings['default-country'] ?? 'BD',
            ],
            'cron-interval-minutes' => [
                'type' => 'text',
                'name' => $this->lang['fields']['cron-interval'] ?? 'Activation check interval (minutes)',
                'description' => $this->lang['fields']['cron-interval-desc'] ?? 'How often the cron polls get.bd for activation of pending domains. Default: 10.',
                'value' => $settings['cron-interval-minutes'] ?? 10,
            ],
            'retry-process-hours' => [
                'type' => 'text',
                'name' => $this->lang['fields']['retry-process'] ?? 'Order process retry interval (hours)',
                'description' => $this->lang['fields']['retry-process-desc'] ?? 'How often to re-call the process endpoint for submitted orders. Default: 6.',
                'value' => $settings['retry-process-hours'] ?? 6,
            ],
        ];
    }

    public function testConnection($config = []): bool
    {
        $this->error = '';

        $settings = (array) ($config['settings'] ?? []);
        if (trim((string) ($settings['api-key'] ?? '')) === '') {
            $this->error = $this->lang['error1'] ?? 'Please enter the get.bd API key.';
            return false;
        }

        try {
            $this->newApiClient($settings)->searchDomain('getbd-connection-check.com.bd');
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
            return false;
        }

        return true;
    }

    /** Overridable factory (test seam). */
    protected function newApiClient(array $settings): ApiClient
    {
        return new ApiClient($settings);
    }

    private function initApi(): void
    {
        if ($this->api)
            return;

        $settings = $this->settings();
        $key = $this->decode_str((string) ($settings['api-key'] ?? ''));
        if ($key !== '')
            $settings['api-key'] = $key;

        $client = $this->newApiClient($settings);
        $client->setLogger(fn($action, $request, $response, $processed = '') => $this->save_log($action, $request, $response, $processed));
        $this->api = $client;
    }

    private function settings(): array
    {
        $settings = $this->config['settings'] ?? [];
        return is_array($settings) ? $settings : [];
    }

    /* ============================================== lifecycle: create */

    public function check($sld = null, $tlds = []): array
    {
        if ($sld == '' || !$tlds) {
            $this->error = $this->lang['error2'] ?? 'Domain and TLD information did not come.';
            return [];
        }
        if (!is_array($tlds))
            $tlds = [$tlds];

        $this->initApi();

        $result = [];
        foreach ($tlds as $tld) {
            $tld = ltrim(strtolower(trim((string) $tld)), '.');
            $result[$tld] = ['status' => 'unknown'];

            $domain = $this->asciiDomain(trim((string) $sld) . '.' . $tld);
            if ($domain === false) {
                $this->error = $this->lang['error3'] ?? 'The domain name is invalid.';
                continue;
            }

            try {
                $response = $this->api->searchDomain($domain);
                $available = (bool) ($response['data']['available'] ?? false);
                $result[$tld]['status'] = $available ? 'available' : 'unavailable';
            } catch (\Throwable $e) {
                $this->error = $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * No registry call at registration time. The service stays inprocess
     * (pending) until the client completes the Verify-to-Active flow.
     */
    public function register(): array|bool
    {
        $serviceId = (int) ($this->service['id'] ?? 0);

        if ($serviceId && ($this->options['getbd_state'] ?? '') === '') {
            $this->options['getbd_state'] = 'awaiting_verification';
            $this->save_options();
        }

        return ['status' => 'inprocess'];
    }

    public function transfer(): array|bool
    {
        $this->error = $this->lang['error4'] ?? 'Domain transfers are not supported for .bd domains.';
        return false;
    }

    /* ============================================ lifecycle: renewal */

    public function renew(): array|bool
    {
        $this->error = '';
        $this->initApi();

        $domain = $this->asciiDomain($this->options['domain'] ?? '');
        if ($domain === false) {
            $this->error = $this->lang['error3'] ?? 'The domain name is invalid.';
            return false;
        }

        $year = (int) ($this->options['year'] ?? $this->service['period_time'] ?? 1) ?: 1;

        try {
            $response = $this->api->renewDomain($domain, $year);
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
            return false;
        }

        if (empty($response['success'])) {
            $this->error = (string) ($response['message'] ?? 'Domain renewal failed.');
            return false;
        }

        return true;
    }

    /* ==================================================== nameservers */

    public function get_nameservers(): array|false
    {
        $this->initApi();

        $info = $this->fetchDomainInfo();
        if ($info === false)
            return false;

        $data = $info['data'] ?? [];
        return array_filter([
            'ns1' => strtolower((string) ($data['primaryDns'] ?? '')),
            'ns2' => strtolower((string) ($data['secondaryDns'] ?? '')),
            'ns3' => strtolower((string) ($data['tertiaryDns'] ?? '')),
        ], fn($v) => $v !== '');
    }

    public function save_nameservers(array $dns): bool
    {
        $this->error = '';
        $this->initApi();

        $domain = $this->asciiDomain($this->options['domain'] ?? '');
        if ($domain === false) {
            $this->error = $this->lang['error3'] ?? 'The domain name is invalid.';
            return false;
        }

        $nameServers = [];
        foreach (array_values($dns) as $ns) {
            $ns = strtolower(trim((string) $ns));
            if ($ns !== '')
                $nameServers[] = $ns;
        }
        $nameServers = array_slice(array_unique($nameServers), 0, 3);

        try {
            $response = $this->api->updateNameservers($domain, $nameServers);
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
            return false;
        }

        if (empty($response['success'])) {
            $this->error = (string) ($response['message'] ?? 'Nameserver update failed.');
            return false;
        }

        return true;
    }

    /* ========================================================== sync */

    public function sync(): array|false
    {
        $this->initApi();

        $info = $this->fetchDomainInfo();
        if ($info === false)
            return false;

        $local = $info['data']['localDomain'] ?? [];
        $expiry = substr((string) ($local['expiryDate'] ?? ($info['data']['expiryDate'] ?? '')), 0, 10);

        if ($expiry === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry)) {
            $this->error = 'No end date information was received.';
            return false;
        }

        return [
            'endtime' => $expiry . ' 00:00:00',
            'status' => !empty($local['isActive']) ? 'active' : 'expired',
        ];
    }

    /* ======================================= verification pipeline === */

    /**
     * Single JSON output path for the verify endpoint. Overridable so
     * tests can capture responses without exit().
     */
    protected function respondJson(string $status, string $message, array $extra = []): void
    {
        $this->responded = true;
        echo \Utility::jencode(array_merge(['status' => $status, 'message' => $message], $extra));
        exit;
    }

    /**
     * Client-area POST endpoint, routed at /getbd-verify via the
     * register:routes hook (see bottom of this file). Creates the get.bd
     * order, uploads the submitted documents and fires the first process
     * attempt. Idempotent per service.
     */
    public function handleVerifySubmission(): void
    {
        if (!headers_sent())
            header('Content-Type: application/json; charset=utf-8');

        try {
            // reachability marker: shows up in module logs even when the
            // request is rejected by an early check
            $this->save_log('verify.request', (string) ($_SERVER['REQUEST_METHOD'] ?? '-'), 'service=' . (string) ($_POST['service_id'] ?? '-'));

            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
                $this->respondJson('error', 'Method not allowed.');
            }

            $member = \UserManager::LoginData("member");
            if (!$member) {
                $this->respondJson('error', $this->lang['verify']['login'] ?? 'Please log in to continue.');
            }

            $ctx = \UserManager::activeAccount();
            $uid = (int) ($ctx["owner_id"] ?? 0);
            if (!$uid) {
                $this->respondJson('error', $this->lang['verify']['login'] ?? 'Please log in to continue.');
            }

            if (!\Validation::verify_csrf_token((string) \Filter::init("POST/token", "hclear"), "domains")) {
                $this->respondJson('error', $this->lang['verify']['csrf'] ?? 'Your session has expired. Please reload the page and try again.');
            }

            $serviceId = (int) \Filter::init("POST/service_id", "rnumbers");
            $service = $serviceId ? \Services::get($serviceId) : false;

            if (
                !$service
                || (int) ($service['owner_id'] ?? 0) !== $uid
                || ($service['type'] ?? '') !== 'domain'
                || ($service['module'] ?? '') !== $this->_name
                || !empty($service['options']['block_access'])
            ) {
                $this->respondJson('error', $this->lang['verify']['not-found'] ?? 'Domain not found.');
            }

            if (!in_array((string) ($service['status'] ?? ''), ['waiting', 'inprocess'], true)) {
                $this->respondJson('error', $this->lang['verify']['not-pending'] ?? 'This domain is not awaiting verification.');
            }

            $this->set_service($serviceId);
            $options = $this->options;

            if (!empty($options['getbd_order_id'])) {
                $this->respondJson('success', $this->lang['verify']['already'] ?? 'Your documents were already submitted and are under BTCL review.');
            }

            /* ---- collect + validate input ---- */

            $post = static fn(string $key): string => trim((string) ($_POST[$key] ?? ''));

            $fullName = $post('full_name');
            if (mb_strlen($fullName) < 3 || mb_strlen($fullName) > 120) {
                $this->respondJson('error', $this->lang['verify']['err-name'] ?? 'Please enter the registrant full name.');
            }

            $nid = preg_replace('/\D+/', '', $post('nid'));
            if (!in_array(strlen($nid), [10, 13, 17], true)) {
                $this->respondJson('error', $this->lang['verify']['err-nid'] ?? 'NID number must be 10, 13, or 17 digits.');
            }

            $email = $post('email');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->respondJson('error', $this->lang['verify']['err-email'] ?? 'Please enter a valid email address.');
            }

            $phone = $this->normalizePhone($post('contact_number'));
            if ($phone === false) {
                $this->respondJson('error', $this->lang['verify']['err-phone'] ?? 'Contact number must be a Bangladeshi mobile number (+8801XXXXXXXXX).');
            }

            $address = $post('contact_address');
            if (mb_strlen($address) < 5 || mb_strlen($address) > 250) {
                $this->respondJson('error', $this->lang['verify']['err-address'] ?? 'Please enter the full contact address.');
            }

            $settings = $this->settings();
            $sendV2 = (bool) ($settings['send-v2-fields'] ?? true);

            $v2 = [
                'companyName' => mb_substr($post('company_name'), 0, 120),
                'city' => mb_substr($post('city'), 0, 60),
                'state' => mb_substr($post('post_state'), 0, 60),
                'postcode' => mb_substr($post('postcode'), 0, 12),
                'country' => strtoupper(mb_substr(preg_replace('/[^A-Za-z]/', '', $post('country')), 0, 2)),
            ];
            if (!$sendV2) {
                $v2 = array_map(static fn($v) => '', $v2);
            }
            if ($v2['country'] === '') {
                $v2['country'] = strtoupper(substr((string) ($settings['default-country'] ?? 'BD'), 0, 2));
            }
            if ($v2['postcode'] !== '' && (!preg_match('/^\d{1,9}$/', $v2['postcode']) || (int) $v2['postcode'] < 1)) {
                $this->respondJson('error', $this->lang['verify']['err-postcode'] ?? 'Postcode must be digits only (e.g. 9100).');
            }

            $nameServers = [];
            for ($i = 1; $i <= 3; $i++) {
                $ns = strtolower($post('ns' . $i));
                if ($ns === '')
                    continue;
                if (!preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $ns)) {
                    $this->respondJson('error', $this->lang['verify']['err-ns'] ?? 'Nameserver format is invalid.');
                }
                if (!in_array($ns, $nameServers, true))
                    $nameServers[] = $ns;
            }

            /* ---- receive + store uploaded documents ---- */

            $stored = $this->storeVerifyDocuments($serviceId);
            if ($stored['error'] !== '') {
                $this->respondJson('error', $stored['error']);
            }
            if (!$stored['files']) {
                $this->respondJson('error', $this->lang['verify']['err-files'] ?? 'Please attach the required documents.');
            }

            /* ---- mark as submitting (guards double-submits) ---- */

            $options['getbd_state'] = 'submitting';
            $options['getbd_error'] = '';
            $options['getbd_submitted_at'] = \DateManager::Now();
            $this->options = $options;
            $this->save_options();

            /* ---- create the get.bd order ---- */

            $this->initApi();

            $domainAscii = $this->asciiDomain($this->options['domain'] ?? '');
            if ($domainAscii === false) {
                $this->options['getbd_state'] = 'awaiting_verification';
                $this->save_options();
                $this->respondJson('error', $this->lang['verify']['err-domain'] ?? 'The domain name is invalid.');
            }

            $year = (int) ($this->service['period_time'] ?? $this->options['year'] ?? 1) ?: 1;

            $payload = [
                'domainName' => $domainAscii,
                'years' => $year,
                'fullName' => $fullName,
                'nid' => $nid,
                'email' => $email,
                'contactAddress' => $address,
                'contactNumber' => $phone,
            ];
            if ($nameServers) {
                $payload['nameServers'] = $nameServers;
            }
            foreach ($v2 as $field => $value) {
                if ($value !== '')
                    $payload[$field] = $value;
            }

            // derived from the payload: identical resubmissions replay the
            // same order; a corrected submission (different payload) gets a
            // different key instead of an idempotency conflict
            $idempotencyKey = 'wisecp-' . $serviceId . '-' . substr(md5(\Utility::jencode($payload)), 0, 16);

            try {
                $orderResponse = $this->api->createOrder($payload, $idempotencyKey);
            } catch (\Throwable $e) {
                $this->options['getbd_state'] = 'awaiting_verification';
                $this->options['getbd_error'] = mb_substr($e->getMessage(), 0, 250);
                $this->save_options();
                $this->save_log('verify.createOrder.failed', \Utility::jencode(['service' => $serviceId]), $e->getMessage());
                $this->respondJson('error', trim(($this->lang['verify']['err-order'] ?? 'The domain could not be reserved. Please try again or contact support.') . ': ' . $e->getMessage(), ': '));
            }

            $orderId = (string) ($orderResponse['data']['id'] ?? '');
            if (empty($orderResponse['success']) || $orderId === '') {
                $apiMessage = (string) ($orderResponse['message'] ?? 'Order creation failed.');
                $this->options['getbd_state'] = 'awaiting_verification';
                $this->options['getbd_error'] = mb_substr($apiMessage, 0, 250);
                $this->save_options();
                $this->respondJson('error', trim(($this->lang['verify']['err-order'] ?? 'The domain could not be reserved. Please try again or contact support.') . ': ' . $apiMessage, ': '));
            }

            /* ---- order exists: persist + upload docs + first process ---- */

            $this->options['getbd_order_id'] = $orderId;
            $this->options['getbd_docs'] = [
                'form' => array_merge([
                    'fullName' => $fullName,
                    'nid' => $nid,
                    'email' => $email,
                    'phone' => $phone,
                    'address' => $address,
                ], array_filter($v2, static fn($v) => $v !== '')),
                'files' => $stored['files'],
            ];

            $uploadFailures = 0;
            foreach ($stored['files'] as $file) {
                try {
                    $this->api->uploadDocument($orderId, (string) $file['type'], (string) $file['path'], (string) $file['name']);
                } catch (\Throwable $e) {
                    $uploadFailures++;
                    $this->save_log('verify.uploadDocument.failed', \Utility::jencode(['service' => $serviceId, 'type' => $file['type'], 'file' => $file['name']]), $e->getMessage());
                }
            }
            if ($uploadFailures) {
                $this->options['getbd_error'] = $uploadFailures . ' document(s) could not be forwarded to get.bd automatically; upload them via the partner portal.';
                $this->save_options();
                $this->notifyAdmin('getbd-document-upload-failed', [
                    'domain'     => (string) ($this->options['domain'] ?? ''),
                    'service_id' => $serviceId,
                    'order_id'   => $orderId,
                    'failed'     => $uploadFailures,
                ], 'warning', ['service_id']);
            }

            $this->options['getbd_state'] = 'submitted';
            $this->save_options();

            try {
                $processResponse = $this->api->processOrder($orderId);
                if (empty($processResponse['success'])) {
                    $message = (string) ($processResponse['message'] ?? '');
                    if ($message !== '' && !str_contains($message, self::PROCESS_DOC_GATE_SNIPPET)) {
                        $this->options['getbd_error'] = mb_substr($message, 0, 250);
                        $this->save_log('verify.processOrder.failed', $orderId, $message);
                        $this->notifyAdmin('getbd-order-process-failed', [
                            'domain'     => (string) ($this->options['domain'] ?? ''),
                            'service_id' => $serviceId,
                            'order_id'   => $orderId,
                            'error'      => mb_substr($message, 0, 200),
                        ], 'warning', ['service_id', 'error']);
                    }
                }
            } catch (\Throwable $e) {
                $message = $e->getMessage();
                if (!str_contains($message, self::PROCESS_DOC_GATE_SNIPPET)) {
                    $this->options['getbd_error'] = mb_substr($message, 0, 250);
                    $this->save_log('verify.processOrder.failed', $orderId, $message);
                    $this->notifyAdmin('getbd-order-process-failed', [
                        'domain'     => (string) ($this->options['domain'] ?? ''),
                        'service_id' => $serviceId,
                        'order_id'   => $orderId,
                        'error'      => mb_substr($message, 0, 200),
                    ], 'warning', ['service_id', 'error']);
                }
            }
            $this->save_options();

            $this->save_log('verify.submitted', \Utility::jencode(['service' => $serviceId, 'order' => $orderId]), 'ok');

            $this->respondJson('success', $this->lang['verify']['done'] ?? 'Documents submitted. Your domain is now under BTCL review and will be activated automatically once approved.');
        } catch (\Throwable $e) {
            if ($this->responded)
                throw $e; // a response was already emitted
            $this->save_log('verify.exception', '', $e->getMessage());
            $this->respondJson('error', $this->lang['verify']['err-generic'] ?? 'Something went wrong. Please try again or contact support.');
        }
    }

    /**
     * Moves submitted documents into storage and returns their metadata.
     * Files land in resources/uploads/documents/getbd/{serviceId}/ with
     * random names — same policy the core uses for domain documents.
     */
    private function storeVerifyDocuments(int $serviceId): array
    {
        $result = ['files' => [], 'error' => ''];

        // per-extension slots (see docSlots): one file per get.bd
        // documentType, required ones enforced here
        $definitions = self::docSlots((string) ($this->options['tld'] ?? ''));

        $allowed = self::DOC_EXTENSIONS;
        $folder = rtrim((string) ROOT_DIR, "\\/") . DS . 'resources' . DS . 'uploads' . DS . 'documents' . DS . 'getbd' . DS . $serviceId . DS;
        if (!is_dir($folder))
            @mkdir($folder, 0755, true);
        if (!is_dir($folder)) {
            $result['error'] = $this->lang['verify']['err-storage'] ?? 'Document storage is not writable. Please contact support.';
            return $result;
        }

        foreach ($definitions as $def) {
            $raw = $_FILES[$def['input']] ?? null;
            if (!is_array($raw) || ($raw['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE || ($raw['name'] ?? '') === '') {
                if (!empty($def['required'])) {
                    $result['error'] = $this->lang['verify']['err-files'] ?? 'Please attach the required documents.'
                        . ' (' . $def['label'] . ')';
                    return $result;
                }
                continue;
            }
            if ((int) ($raw['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                $result['error'] = $def['label'] . ': ' . ($this->lang['verify']['err-file-upload'] ?? 'the file could not be uploaded. Please try again.');
                return $result;
            }

            $single = [
                'name' => (string) ($raw['name'] ?? ''),
                'type' => (string) ($raw['type'] ?? ''),
                'tmp_name' => (string) ($raw['tmp_name'] ?? ''),
                'error' => (int) ($raw['error'] ?? UPLOAD_ERR_OK),
                'size' => (int) ($raw['size'] ?? 0),
            ];

            $ext = strtolower(pathinfo($single['name'], PATHINFO_EXTENSION));
            if ($ext === '' || !in_array($ext, $allowed, true) || \Uploads::is_executable_ext($ext)) {
                $result['error'] = $this->lang['verify']['err-file-type'] ?? 'Document type is not allowed. Accepted: images, PDF, DOC, DOCX.';
                return $result;
            }
            if ($single['size'] <= 0 || $single['size'] > 5 * 1024 * 1024) {
                $result['error'] = $this->lang['verify']['err-file-size'] ?? 'Each document must be at most 5 MB.';
                return $result;
            }

            $upload = new \Uploads($single, [
                'folder' => $folder,
                'file-name' => 'random',
                'date' => false,
                'allowed-ext' => implode(',', $allowed),
                'max-file-size' => 5 * 1024 * 1024,
            ]);

            if (!$upload->processed()) {
                $result['error'] = trim((string) $upload->error) !== ''
                    ? (string) $upload->error
                    : ($this->lang['verify']['err-file-upload'] ?? 'A document could not be uploaded. Please try again.');
                return $result;
            }

            $op = current($upload->operands) ?: [];
            if (!$op) {
                $result['error'] = $this->lang['verify']['err-file-upload'] ?? 'A document could not be uploaded. Please try again.';
                return $result;
            }

            $result['files'][] = [
                'type' => $def['documentType'],
                'name' => (string) ($op['file_name'] ?? $single['name']),
                'path' => rtrim($folder, '\\/') . DS . (string) ($op['name'] ?? ''),
                'size' => (int) ($op['size'] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * Live submission-state for the logged-in client's pending GetBD
     * domains, served through the Addons bridge. Page HTML can be cached;
     * this endpoint is asked fresh by the modal JS on every page load.
     */
    /**
     * Live submission-state for the logged-in client's pending GetBD
     * domains, served through the Addons bridge. Page HTML can be cached;
     * this endpoint is asked fresh by the modal JS on every page load.
     */
    public function respondPendingState(string $bridgeVersion = ''): void
    {
        if (!headers_sent())
            header('Cache-Control: no-store');
        $states = new \stdClass();
        try {
            $member = \UserManager::LoginData('member');
            $ctx = $member ? \UserManager::activeAccount() : [];
            $uid = (int) ($ctx['owner_id'] ?? 0);
            if ($uid) {
                $q = \WDB::select('id, name, status, options')->from('users_products');
                $q->where('type', '=', 'domain', '&&');
                $q->where('module', '=', 'GetBD', '&&');
                $q->where('owner_id', '=', $uid);

                // FIX: fetch_assoc() returns a list of rows; getAssoc() only returns one row
                $rows = $q->build() ? \WDB::fetch_assoc() : [];
                $list = [];
                foreach ((array) $rows as $row) {
                    $rowData = is_object($row) ? (array) $row : (is_array($row) ? $row : []);

                    if (empty($rowData)) {
                        continue;
                    }

                    $rowId = (string) ($rowData['id'] ?? '');
                    $status = (string) ($rowData['status'] ?? '');
                    if (!in_array($status, ['waiting', 'inprocess'], true)) {
                        continue;
                    }

                    $opts = \Utility::jdecode((string) ($rowData['options'] ?? ''), true);
                    if (!is_array($opts))
                        $opts = [];

                    $hasOrder = !empty($opts['getbd_order_id']);
                    $state = (string) ($opts['getbd_state'] ?? '');
                    $isSubmitted = $hasOrder || in_array($state, ['submitting', 'submitted'], true);
                    $list[$rowId] = [
                        'domain' => (string) ($rowData['name'] ?? ''),
                        'submitted' => $isSubmitted,
                    ];
                }
                if ($list)
                    $states = $list;
            } else {
            }
        } catch (\Throwable $e) {
            $this->save_log('state.exception', '', $e->getMessage());
        }

        $this->respondJson('success', 'ok', [
            'states' => $states,
            'bridge' => $bridgeVersion,
        ]);
    }

    /* ================================================== cron: activate */

    /**
     * Polls get.bd for activation of submitted (still pending) domains and
     * flips them active. Self-throttled via options.getbd_last_cron.
     */
    public function activationCron(): void
    {
        try {
            $settings = $this->settings();
            $interval = max(1, (int) ($settings['cron-interval-minutes'] ?? 10));
            $retryH = max(1, (int) ($settings['retry-process-hours'] ?? 6));

            $query = \WDB::select('id, name, options, duedate')->from('users_products');
            $query->where('type', '=', 'domain', '&&');
            $query->where('module', '=', $this->_name, '&&');
            $query->where('status', '=', 'inprocess');

            // FIX: Use fetch_assoc() to get all rows for cron processing
            $rows = $query->build() ? \WDB::fetch_assoc() : [];
            if (!is_array($rows))
                return;

            foreach ($rows as $row) {
                $rowData = is_object($row) ? (array) $row : (is_array($row) ? $row : []);
                if (empty($rowData))
                    continue;

                $serviceId = (int) ($rowData['id'] ?? 0);
                if (!$serviceId)
                    continue;

                $options = \Utility::jdecode((string) ($rowData['options'] ?? ''), true);
                if (!is_array($options))
                    $options = [];

                $orderId = (string) ($options['getbd_order_id'] ?? '');
                if ($orderId === '')
                    continue;
                // stamp before the API call so concurrent cron hooks don't double-poll
                $this->set_service($serviceId);
                $this->options['getbd_last_cron'] = \DateManager::Now();
                $this->save_options();

                $this->pollServiceActivation($serviceId, $orderId, $retryH);
            }
        } catch (\Throwable $e) {
            $this->save_log('cron.exception', '', $e->getMessage());
        }
    }

    private function pollServiceActivation(int $serviceId, string $orderId, int $retryHours): void
    {
        $this->initApi();

        $domain = $this->asciiDomain($this->options['domain'] ?? '');
        if ($domain === false)
            return;

        try {
            $info = $this->api->getDomainInfo($domain);
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            if (stripos($message, 'RESERVATION_EXPIRED') !== false || stripos($message, 'not found') !== false) {
                $this->options['getbd_error'] = mb_substr($message, 0, 250);
                $this->save_options();
                $this->save_log('cron.reservationProblem', \Utility::jencode(['service' => $serviceId, 'order' => $orderId]), $message);
                $this->notifyAdmin('getbd-reservation-problem', [
                    'domain'     => (string) ($this->options['domain'] ?? ''),
                    'service_id' => $serviceId,
                    'order_id'   => $orderId,
                    'error'      => mb_substr($message, 0, 200),
                ], 'error', ['service_id', 'error']);
            }
            return;
        }

        $local = $info['data']['localDomain'] ?? [];

        if (!empty($local['isActive'])) {
            $expiry = substr((string) ($local['expiryDate'] ?? ($info['data']['expiryDate'] ?? '')), 0, 10);

            $this->options['getbd_state'] = 'active';
            $this->options['getbd_error'] = '';
            $this->options['getbd_activated_at'] = \DateManager::Now();
            $this->options['getbd_activation_date'] = $expiry;

            $update = ['options' => $this->options];
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry)) {
                $update['duedate'] = $expiry;
            }
            \Services::set($serviceId, $update);
            \Services::change_status($serviceId, 'active', [
                'module_completed' => true,
                'module_action' => 'register',
            ]);

            $this->save_log('cron.activated', \Utility::jencode(['service' => $serviceId, 'order' => $orderId, 'expiry' => $expiry]), 'ok');
            $this->notifyAdmin('getbd-domain-activated', [
                'domain'     => (string) ($this->options['domain'] ?? ''),
                'service_id' => $serviceId,
                'order_id'   => $orderId,
                'expiry'     => $expiry,
            ], 'success');
            return;
        }

        // not active yet: periodically re-attempt processing so the order
        // completes as soon as BTCL approves the documents
        $lastRetry = isset($this->options['getbd_last_process_retry'])
            ? strtotime((string) $this->options['getbd_last_process_retry'])
            : false;
        if ($lastRetry && (time() - $lastRetry) < $retryHours * 3600)
            return;

        $this->options['getbd_last_process_retry'] = \DateManager::Now();
        $this->save_options();

        try {
            $response = $this->api->processOrder($orderId);
            if (empty($response['success'])) {
                $message = (string) ($response['message'] ?? '');
                if ($message !== '' && !str_contains($message, self::PROCESS_DOC_GATE_SNIPPET)) {
                    $this->options['getbd_error'] = mb_substr($message, 0, 250);
                    $this->save_options();
                    $this->save_log('cron.processOrder.failed', $orderId, $message);
                    $this->notifyAdmin('getbd-order-process-failed', [
                        'domain'     => (string) ($this->options['domain'] ?? ''),
                        'service_id' => $serviceId,
                        'order_id'   => $orderId,
                        'error'      => mb_substr($message, 0, 200),
                    ], 'warning', ['service_id', 'error']);
                }
            }
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            if (!str_contains($message, self::PROCESS_DOC_GATE_SNIPPET)) {
                $this->options['getbd_error'] = mb_substr($message, 0, 250);
                $this->save_options();
                $this->save_log('cron.processOrder.failed', $orderId, $message);
                $this->notifyAdmin('getbd-order-process-failed', [
                    'domain'     => (string) ($this->options['domain'] ?? ''),
                    'service_id' => $serviceId,
                    'order_id'   => $orderId,
                    'error'      => mb_substr($message, 0, 200),
                ], 'warning', ['service_id', 'error']);
            }
        }
    }

    /* ================================================== client UI ==== */

    /** Per-extension document summary shown in the modal (official table). */
    public static function docMatrix(): array
    {
        return [
            'bd' => ['NID or passport'],
            'com.bd' => ['Trade licence', 'NID'],
            'net.bd' => ['NID or trade licence'],
            'org.bd' => ['Registration certificate'],
            'edu.bd' => ['EIIN or UGC approval'],
            'info.bd' => ['NID or trade licence'],
            'id.bd' => ['NID or passport'],
            'sch.bd' => ['EIIN certificate'],
            'co.bd' => ['Trade licence + NID'],
            'ai.bd' => ['NID or trade licence'],
            'tv.bd' => ['NID or trade licence'],
            'বাংলা' => ['NID or trade licence'],
        ];
    }

    /**
     * Modal + JavaScript injected into the client area. The same payload
     * serves the domains list and the domain detail page; the JS wires up
     * whichever context it finds.
     */
    public static function clientAssets(): string
    {
        if (\defined('CRON'))
            return ''; // no client UI inside cron context

        self::ensureAddonBridge();

        // Per-service submission state for the logged-in client: which
        // pending domains already have a get.bd order (docs submitted).
        // The modal JS turns the button into a review chip for these.
        $stateMap = [];
        try {
            $member = \UserManager::LoginData('member');
            if ($member && !empty($member['id'])) {
                $q = \WDB::select('id, status, options')->from('users_products');
                $q->where('type', '=', 'domain', '&&');
                $q->where('module', '=', 'GetBD', '&&');
                $q->where('owner_id', '=', (int) $member['id']);

                // FIX: Use fetch_assoc() to get all rows
                $rows = $q->build() ? \WDB::fetch_assoc() : [];

                foreach ((array) $rows as $row) {
                    $rowData = is_object($row) ? (array) $row : (is_array($row) ? $row : []);
                    if (empty($rowData))
                        continue;

                    if (!in_array((string) ($rowData['status'] ?? ''), ['waiting', 'inprocess'], true))
                        continue;

                    $opts = \Utility::jdecode((string) ($rowData['options'] ?? ''), true);
                    if (!is_array($opts))
                        continue;

                    $stateMap[(string) (int) $rowData['id']] = [
                        'submitted' => !empty($opts['getbd_order_id']) || in_array((string) ($opts['getbd_state'] ?? ''), ['submitting', 'submitted', 'active'], true),
                    ];
                }
            }
        } catch (\Throwable $e) {
            $stateMap = [];
        }

        // Endpoint candidates, tried in order by the modal's JS.
        //
        // 1. Addons bridge (guaranteed): the website addon controller
        //    loads the addon module itself on request, so it works on
        //    every install regardless of routing/hook timing.
        // 2. register:routes website route — only exists on installs
        //    where module files load before route collection.
        //
        // Client URL shapes vary with the "rich-url" setting (/route,
        // /index.php?route=, /index.php/route) and installs may live in a
        // subdirectory — LinkGenerator handles that per route key.
        $endpoints = [];

        try {
            $addonUrl = (string) \LinkGenerator::client('addon/' . self::BRIDGE_DIR);
            if ($addonUrl !== '' && str_contains($addonUrl, self::BRIDGE_DIR)) {
                $endpoints[] = ['url' => $addonUrl, 'via' => 'addon'];
            }
        } catch (\Throwable $e) {
            // LinkGenerator unavailable — the route candidates below still apply
        }

        try {
            $routeUrl = (string) \LinkGenerator::client('getbd-verify');
            if ($routeUrl !== '' && str_contains($routeUrl, 'getbd-verify')) {
                $endpoints[] = ['url' => $routeUrl, 'via' => 'route'];
            }
        } catch (\Throwable $e) {
            // ignore
        }

        $rich = (string) (\Config::get('general/rich-url') ?: '');
        $add = (!$rich || $rich === 'on') ? '/' : ($rich === 'off2' ? '/index.php?route=' : '/index.php/');
        $manual = rtrim(\Utility::AppAdress(), '/') . $add . 'getbd-verify';
        if (!in_array($manual, array_column($endpoints, 'url'), true)) {
            $endpoints[] = ['url' => $manual, 'via' => 'route'];
        }

        $endpoint = rtrim(\Utility::AppAdress(), '/') . '/getbd-verify';
        $csrf = \Validation::get_csrf_token('domains', true);
        $lang = [
            'title' => 'Verify to Active',
            'lead' => 'Complete the required verification to activate your .bd domain.',
            'button' => 'Verify to Active',
            'review' => 'Under BTCL review',
            'documents' => 'Required documents',
            'docNid' => 'NID document (required)',
            'docSecondary' => 'Supporting document (required)',
            'docTrade' => 'Trade license / business registration (required)',
            'docPassport' => 'Passport copy (optional)',
            'fullName' => 'Registrant full name',
            'nid' => 'NID number (10 / 13 / 17 digits)',
            'email' => 'Email address',
            'phone' => 'Contact number (+8801XXXXXXXXX)',
            'address' => 'Contact address',
            'company' => 'Company name (optional)',
            'city' => 'City (optional)',
            'state' => 'State / Division (optional)',
            'postcode' => 'Postcode (optional)',
            'country' => 'Country (optional, ISO code)',
            'ns' => 'Nameserver :n (optional)',
            'submit' => 'Submit documents',
            'submitting' => 'Submitting…',
            'note' => 'BTCL reviews the documents manually; the domain activates automatically after approval. Orders reserve the domain for 7 days.',
            'errFiles' => 'Please attach the required documents.',
            'errPostcode' => 'Postcode must be digits only (e.g. 9100).',
            'errPhone' => 'Contact number must look like +8801XXXXXXXXX.',
            'errNid' => 'NID number must be 10, 13, or 17 digits.',
            'done' => 'Documents submitted — your domain is under BTCL review.',
            'failed' => 'Submission failed:',
        ];

        $jsJson = fn($v): string => json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $docDefs = [];
        foreach (array_merge(self::docMatrix(), array_fill_keys(['ac.bd', 'gov.bd', 'mil.bd', 'biz.bd'], [])) as $tld => $items) {
            $slots = self::docSlots($tld);
            $secondary = null;
            $passport = false;
            foreach ($slots as $slot) {
                if ($slot['input'] === 'doc_secondary') {
                    $secondary = ['type' => $slot['documentType'], 'label' => $slot['label'], 'required' => (bool) $slot['required']];
                }
                if ($slot['input'] === 'doc_passport')
                    $passport = true;
            }
            $docDefs[$tld] = ['checklist' => $items, 'secondary' => $secondary, 'passport' => $passport];
        }
        $docsJson = $jsJson($docDefs);
        $stateJson = $jsJson($stateMap ?: new \stdClass());
        $langJson = $jsJson($lang);
        $endpointsJson = $jsJson($endpoints ?: [['url' => rtrim(\Utility::AppAdress(), '/') . '/getbd-verify', 'via' => 'route']]);

        return <<<HTML
<div class="modal fade" id="getbdVerifyModal" tabindex="-1" aria-hidden="true" data-getbd-version="1.4.0">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-patch-check me-2"></i>{$lang['title']} — <span id="getbd-m-domain"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="getbd-alert" class="alert alert-danger d-none"></div>
                <p class="text-muted small">{$lang['lead']}</p>
                <form id="getbd-verify-form">
                    {$csrf}
                    <input type="hidden" name="service_id" id="getbd-service-id" value="">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label">{$lang['fullName']} *</label><input type="text" class="form-control" name="full_name" required maxlength="120"></div>
                        <div class="col-md-6"><label class="form-label">{$lang['nid']} *</label><input type="text" class="form-control" name="nid" required maxlength="17" inputmode="numeric"></div>
                        <div class="col-md-6"><label class="form-label">{$lang['email']} *</label><input type="email" class="form-control" name="email" required maxlength="120"></div>
                        <div class="col-md-6"><label class="form-label">{$lang['phone']} *</label><input type="text" class="form-control" name="contact_number" required maxlength="20" placeholder="+8801XXXXXXXXX"></div>
                        <div class="col-12"><label class="form-label">{$lang['address']} *</label><textarea class="form-control" name="contact_address" rows="2" required maxlength="250"></textarea></div>
                        <div class="col-md-6"><label class="form-label">{$lang['company']}</label><input type="text" class="form-control" name="company_name" maxlength="120"></div>
                        <div class="col-md-6"><label class="form-label">{$lang['country']}</label><input type="text" class="form-control" name="country" maxlength="2" placeholder="BD"></div>
                        <div class="col-md-4"><label class="form-label">{$lang['city']}</label><input type="text" class="form-control" name="city" maxlength="60"></div>
                        <div class="col-md-4"><label class="form-label">{$lang['state']}</label><input type="text" class="form-control" name="post_state" maxlength="60"></div>
                        <div class="col-md-4"><label class="form-label">{$lang['postcode']}</label><input type="text" class="form-control" name="postcode" maxlength="12"></div>
                        <div class="col-12"><label class="form-label fw-semibold">{$lang['documents']}</label><div id="getbd-doc-list" class="small text-muted mb-2"></div></div>
                        <div class="col-md-6"><label class="form-label">{$lang['docNid']}</label><input type="file" class="form-control" name="doc_nid" required accept=".jpg,.jpeg,.png,.bmp,.webp,.gif,.pdf,.doc,.docx"></div>
                        <div class="col-md-6 d-none" id="getbd-sec-row"><label class="form-label"><span id="getbd-lbl-sec"></span></label><input type="file" class="form-control" name="doc_secondary" accept=".jpg,.jpeg,.png,.bmp,.webp,.gif,.pdf,.doc,.docx"></div>
                        <div class="col-12 d-none" id="getbd-pass-row"><label class="form-label"><span id="getbd-lbl-pass"></span></label><input type="file" class="form-control" name="doc_passport" accept=".jpg,.jpeg,.png,.bmp,.webp,.gif,.pdf,.doc,.docx"></div>
                        <div class="col-md-4"><label class="form-label">{$lang['ns']}</label><input type="text" class="form-control" name="ns1" placeholder="ns1.example.com"></div>
                        <div class="col-md-4"><label class="form-label">&nbsp;</label><input type="text" class="form-control" name="ns2" placeholder="ns2.example.com"></div>
                        <div class="col-md-4"><label class="form-label">&nbsp;</label><input type="text" class="form-control" name="ns3" placeholder="ns3.example.com"></div>
                    </div>
                    <p class="small text-muted mt-3 mb-0"><i class="bi bi-info-circle me-1"></i>{$lang['note']}</p>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-soft" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="getbd-submit"><i class="bi bi-send me-1"></i>{$lang['submit']}</button>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    var VERSION = '1.4.0';
    var L = {$langJson};
    var DOCS = {$docsJson};
    var STATE = {$stateJson};
    var ENDPOINTS = {$endpointsJson};



    var BD_TLDS = ['bd','com.bd','net.bd','org.bd','edu.bd','gov.bd','ac.bd','mil.bd','info.bd','tv.bd','co.bd','ai.bd','sch.bd','id.bd','biz.bd'];
    var currentDef = null;

    function isBdDomain(name) {
        name = (name || '').toLowerCase().trim();
        if (!name) return false;
        if (/[\u0980-\u09FF]/.test(name)) return true; // Bengali IDN (.বাংলা)
        var labels = name.split('.');
        var n = labels.length;
        if (labels[n - 1] === 'bd') return true;
        var tld = n > 2 ? labels[n - 2] + '.' + labels[n - 1] : labels[n - 1];
        return BD_TLDS.indexOf(tld) !== -1;
    }

    function tldOf(name) {
        var labels = (name || '').toLowerCase().split('.');
        var n = labels.length;
        return n > 2 ? labels[n - 2] + '.' + labels[n - 1] : labels[n - 1];
    }

    function reviewChip() {
        var chip = document.createElement('button');
        chip.type = 'button';
        chip.className = 'btn btn-soft btn-sm getbd-review-chip'; // Added specific class
        chip.disabled = true;
        chip.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>' + L.review;
        return chip;
    }

        function injectListButtons() {
        var rows = document.querySelectorAll('article[data-status="pending"][data-name]');
        rows.forEach(function (row) {
            var name = (row.getAttribute('data-name') || '').toLowerCase();
            if (!isBdDomain(name)) return;
            var actions = row.querySelector('.list-actions');
            // Check for both button and chip to prevent duplicates
            if (!actions || actions.querySelector('.getbd-verify-btn, .getbd-review-chip')) return;

            var id = 0;
            var link = actions.querySelector('a[href*="domain-detail"]');
            if (link) {
                var m = link.getAttribute('href').match(/domain-detail[\/=](\d+)/);
                if (m) id = m[1];
            }
            if (!id) return;
            if (STATE[id] && STATE[id].submitted) {
                actions.insertBefore(reviewChip(), actions.firstChild);
                return;
            }
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-primary btn-sm getbd-verify-btn';
            btn.setAttribute('data-id', id);
            btn.setAttribute('data-domain', name);
            btn.innerHTML = '<i class="bi bi-patch-check me-1"></i>' + L.button;
            btn.addEventListener('click', function () { openModal(id, name); });
            actions.insertBefore(btn, actions.firstChild);
        });
    }

    function injectDetailButton() {
        var ctx = document.querySelector('section[data-domain-detail][data-id]');
        if (!ctx) return;
        var titleEl = document.querySelector('.sd-hero-title');
        if (!titleEl || !isBdDomain(titleEl.textContent)) return;
        if (!document.querySelector('.sd-status-pending')) return;
        if (document.querySelector('.getbd-verify-btn')) return;

        var id = ctx.getAttribute('data-id');
        var name = titleEl.textContent.toLowerCase().trim();

        var el;
        if (STATE[id] && STATE[id].submitted) {
            el = reviewChip();
            el.className = 'btn btn-soft btn-sm mt-2';
        } else {
            el = document.createElement('button');
            el.type = 'button';
            el.className = 'btn btn-primary btn-sm mt-2 getbd-verify-btn';
            el.innerHTML = '<i class="bi bi-patch-check me-1"></i>' + L.button;
            el.addEventListener('click', function () { openModal(id, name); });
        }

        var host = document.querySelector('.sd-hero-id') || ctx;
        host.appendChild(el);
    }

    function openModal(serviceId, domain) {
        var modalEl = document.getElementById('getbdVerifyModal');
        if (!modalEl || typeof bootstrap === 'undefined') return;

        modalEl.querySelector('#getbd-m-domain').textContent = domain;
        modalEl.querySelector('#getbd-service-id').value = serviceId;
        modalEl.querySelector('#getbd-alert').classList.add('d-none');

        var docList = modalEl.querySelector('#getbd-doc-list');
        var tld = tldOf(domain);
        var def = DOCS[tld] || DOCS['bd'] || { checklist: [], secondary: null, passport: false };
        currentDef = def;
        var items = def.checklist || [];
        docList.innerHTML = '';
        items.forEach(function (item) {
            var div = document.createElement('div');
            div.textContent = '- ' + item;
            docList.appendChild(div);
        });

        var form = modalEl.querySelector('#getbd-verify-form');
        form.reset();
        modalEl.querySelector('input[name="country"]').value = '';

        var secRow = modalEl.querySelector('#getbd-sec-row');
        var passRow = modalEl.querySelector('#getbd-pass-row');
        var secInput = modalEl.querySelector('input[name="doc_secondary"]');
        if (def.secondary) {
            secRow.classList.remove('d-none');
            modalEl.querySelector('#getbd-lbl-sec').textContent = def.secondary.label + (def.secondary.required ? '' : ' (optional)');
            secInput.required = !!def.secondary.required;
        } else {
            secRow.classList.add('d-none');
            secInput.required = false;
            secInput.value = '';
        }
        if (def.passport) {
            passRow.classList.remove('d-none');
            modalEl.querySelector('#getbd-lbl-pass').textContent = 'Passport copy (optional)';
        } else {
            passRow.classList.add('d-none');
            modalEl.querySelector('input[name="doc_passport"]').value = '';
        }

        var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();
    }

    function submitForm() {
        var modalEl = document.getElementById('getbdVerifyModal');
        var form = document.getElementById('getbd-verify-form');
        var alertBox = document.getElementById('getbd-alert');
        var btn = document.getElementById('getbd-submit');

        alertBox.classList.add('d-none');

        var nid = form.querySelector('input[name="nid"]').value.replace(/\\D/g, '');
        if ([10, 13, 17].indexOf(nid.length) === -1) { return show(L.errNid); }

        if (!form.querySelector('input[name="doc_nid"]').files.length ||
            (currentDef && currentDef.secondary && currentDef.secondary.required &&
             !form.querySelector('input[name="doc_secondary"]').files.length)) {
            return show(L.errFiles);
        }

        var phone = form.querySelector('input[name="contact_number"]').value.replace(/[\\s\\-()]/g, '');
        if (phone.indexOf('+') !== 0 && phone.indexOf('880') === 0) phone = '+' + phone;
        if (phone.indexOf('+') !== 0 && phone.indexOf('0') === 0) phone = '+880' + phone.substring(1);
        if (!/^\\+8801[3-9]\\d{8}$/.test(phone)) return show(L.errPhone);
        form.querySelector('input[name="contact_number"]').value = phone;

        btn.disabled = true;
        var original = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i>' + L.submitting;

        var lastProblem = '';

        function restore() {
            btn.disabled = false;
            btn.innerHTML = original;
        }

        function success(data) {
            modalEl.querySelector('.modal-body').innerHTML =
                '<div class="text-center py-4"><i class="bi bi-patch-check-fill text-success" style="font-size:2.5rem"></i>' +
                '<p class="mt-3 mb-0">' + (data.message || L.done) + '</p></div>';
            modalEl.querySelector('.modal-footer').innerHTML = '';
            setTimeout(function () { window.location.reload(); }, 2500);
        }

        function attempt(i) {
            if (i >= ENDPOINTS.length) {
                restore();
                show(lastProblem || L.failed);
                return;
            }
            var ep = ENDPOINTS[i] || {};
            var fd = new FormData(form);
            if (ep.via === 'addon') {
                fd.append('operation', 'use_addon_method');
                fd.append('method', 'verify_submit');
            }
            fetch(ep.url, {
                method: 'POST',
                body: fd,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (r) {
                return r.text().then(function (t) { return { httpStatus: r.status, text: t }; });
            }).then(function (res) {
                var data = null;
                try { data = JSON.parse(res.text); } catch (e) {}
                if (data && (data.status === 'success' || data.status === 'error')) {
                    if (data.status === 'success') { success(data); return; }
                    restore();
                    show(data.message || L.failed);
                    return;
                }
                lastProblem = L.failed + ' unexpected server response (HTTP ' + res.httpStatus +
                    (res.text ? ': ' + String(res.text).replace(/<[^>]*>/g, ' ').replace(/\\s+/g, ' ').substring(0, 140) : '') + ')';
                attempt(i + 1);
            }).catch(function (err) {
                lastProblem = L.failed + ' request failed (' + (err && err.message ? err.message : 'network error') + ')';
                attempt(i + 1);
            });
        }

        attempt(0);

        function show(msg) {
            alertBox.textContent = msg;
            alertBox.classList.remove('d-none');
            try { alertBox.scrollIntoView({ block: 'nearest' }); } catch (e) {}
        }
    }

    function addonEndpoint() {
        for (var i = 0; i < ENDPOINTS.length; i++) {
            if (ENDPOINTS[i] && ENDPOINTS[i].via === 'addon') return ENDPOINTS[i].url;
        }
        return '';
    }

    // Ask the bridge for the live submission state (page HTML may be cached;
    // this response is not). Falls back to the embedded STATE on failure.
    function loadState(done) {
        var url = addonEndpoint();
        if (!url) { 
            done(); 
            return; 
        }
        var fd = new FormData();
        fd.append('operation', 'use_addon_method');
        fd.append('method', 'verify_state');
        fetch(url, {
            method: 'POST',
            body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) {
            return r.text().then(function (t) { return t; });
        }).then(function (text) {
            var data = null;
            try { data = JSON.parse(text); } catch (e) {}
            if (data && data.status === 'success' && data.states) STATE = data.states;
            done();
        }).catch(function () {
            done();
        });
    }

    function init() {
        var submit = document.getElementById('getbd-submit');
        if (submit && !submit.dataset.getbdBound) {
            submit.dataset.getbdBound = '1';
            submit.addEventListener('click', submitForm);
        }
        loadState(function () {
            injectListButtons();
            injectDetailButton();
        });
    }

    if (document.readyState !== 'loading') init();
    else document.addEventListener('DOMContentLoaded', init);
})();
</script>
HTML;
    }

    /* ==================================================== helpers ===== */

    /**
     * Admin-panel notification (the bell). Uses the core Admin::notify —
     * deduplicated while a matching event is still pending, so a persistent
     * failure (e.g. empty wallet) notifies once, not per cron tick. Never
     * throws: notification problems must not break the flow.
     *
     * The admin bell ONLY lists events with owner='system' (plus per-admin
     * user_id-scoped ones) — a custom owner would be created but never shown.
     * The bell renders data['message'] as the text and auto-links
     * service_name + service_id to the admin service page.
     */
    private function notifyAdmin(string $name, array $data, string $level = 'error', array $dedupeKeys = []): void
    {
        try {
            if (!class_exists('\Admin', false) || !method_exists('\Admin', 'notify')) {
                $this->save_log('notify.unavailable', $name, 'Admin::notify not found');
                return;
            }
            $payload = $data;
            $payload['service_name'] = (string) ($data['domain'] ?? '');
            if (($payload['message'] ?? '') === '') {
                $payload['message'] = self::notificationText($name, $data);
            }
            \Admin::notify($name, $payload, $level, [
                'owner'       => 'system',
                'dedupe'      => (bool) $dedupeKeys,
                'dedupe_keys' => $dedupeKeys,
            ]);
        } catch (\Throwable $e) {
            $this->save_log('notify.failed', $name, $e->getMessage());
        }
    }

    private static function notificationText(string $name, array $d): string
    {
        $domain = (string) ($d['domain'] ?? 'unknown');
        $error  = (string) ($d['error'] ?? '');
        switch ($name) {
            case 'getbd-domain-activated':
                return '.bd domain ' . $domain . ' has been activated at the registry (expiry ' . (string) ($d['expiry'] ?? '?') . ').';
            case 'getbd-order-create-failed':
                return 'get.bd order creation failed for ' . $domain . ($error !== '' ? ': ' . $error : '');
            case 'getbd-order-process-failed':
                return 'get.bd order processing failed for ' . $domain . ($error !== '' ? ': ' . $error : '') . ' — processing retries automatically.';
            case 'getbd-document-upload-failed':
                return (string) ($d['failed'] ?? 'Some') . ' document(s) for ' . $domain . ' could not be uploaded to get.bd — attach them via the partner portal (order ' . (string) ($d['order_id'] ?? '?') . ').';
            case 'getbd-reservation-problem':
                return 'get.bd reservation problem for ' . $domain . ($error !== '' ? ': ' . $error : '') . ' — the order may need to be recreated.';
        }
        return 'GetBD: ' . str_replace(['getbd-', '-'], ['', ' '], $name);
    }

    /**
     * Installs/refreshes the Addons bridge (coremio/modules/Addons/GetBDVerify/)
     * that hosts the client-area verify endpoint. Runs from client-area
     * renders and settings saves, so no manual step is ever needed.
     */
    private static function ensureAddonBridge(): void
    {
        try {
            $source = MODULE_DIR . 'Registrars' . DS . 'GetBD' . DS . 'addon-bridge' . DS;
            if (!is_dir($source))
                return;

            $target = MODULE_DIR . 'Addons' . DS . self::BRIDGE_DIR . DS;

            foreach (self::BRIDGE_FILES as $file) {
                $srcPath = $source . $file;
                if (!is_file($srcPath))
                    continue;

                $src = (string) file_get_contents($srcPath);
                if (is_file($target . $file) && (string) file_get_contents($target . $file) === $src)
                    continue;

                if (!is_dir($target))
                    @mkdir($target, 0755, true);
                \FileManager::file_write($target . $file, $src);
            }
        } catch (\Throwable $e) {
            \Modules::save_log('Registrars', 'GetBD', 'bridge.provision', '', $e->getMessage());
        }
    }

    private function asciiDomain(string $domain): string|false
    {
        $domain = strtolower(trim($domain));
        if ($domain === '')
            return false;
        $ascii = idn_to_ascii($domain, 0, INTL_IDNA_VARIANT_UTS46);
        if ($ascii === false || !str_contains($ascii, '.'))
            return false;
        return $ascii;
    }

    private function normalizePhone(string $raw): string|false
    {
        $phone = preg_replace('/[\s\-().]/', '', trim($raw));
        if ($phone === null || $phone === '')
            return false;
        if ($phone[0] !== '+') {
            if (str_starts_with($phone, '880'))
                $phone = '+' . $phone;
            elseif (str_starts_with($phone, '0'))
                $phone = '+880' . substr($phone, 1);
            else
                return false;
        }
        return preg_match('/^\+8801[3-9]\d{8}$/', $phone) ? $phone : false;
    }

    private function fetchDomainInfo(): array|false
    {
        $domain = $this->asciiDomain($this->options['domain'] ?? '');
        if ($domain === false) {
            $this->error = $this->lang['error3'] ?? 'The domain name is invalid.';
            return false;
        }

        try {
            $response = $this->api->getDomainInfo($domain);
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
            return false;
        }

        if (empty($response['success']) || empty($response['data'])) {
            $this->error = (string) ($response['message'] ?? 'Unable to retrieve domain information.');
            return false;
        }

        return $response;
    }

}

/* ====================================================== hook wiring ====
 * Registered at file scope (same pattern as the bundled DomainNameAPI
 * module): the WiseCP bootstrap includes active module files on every
 * request, which is what puts these hooks on the Hook registry.
 */

\Hook::add('register:routes', 1, function (\Router $router): void {
    $router->add('getbd-verify', 'getbd-verify', static function (): void {
        \Modules::Load('Registrars', 'GetBD');
        $module = new GetBD();
        $module->handleVerifySubmission();
    });
});

\Hook::add('PerMinuteCronJob', 1, function (): void {
    \Modules::Load('Registrars', 'GetBD');
    $module = new GetBD();
    $module->activationCron();
});

// newer builds fire the per-minute cron under this name as well; the cron is
// self-throttled per service, so double registration is harmless
\Hook::add('action:cron.minute.run', 1, function (): void {
    \Modules::Load('Registrars', 'GetBD');
    $module = new GetBD();
    $module->activationCron();
});

\Hook::add('ui:client.domains_list.modals.end', 1, fn(): string => GetBD::clientAssets());
\Hook::add('ui:client.domain_detail.modals.end', 1, fn(): string => GetBD::clientAssets());
