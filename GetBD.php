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

        /** Guard so a response is only emitted once (test seam re-throws). */
        protected bool $responded = false;

        /* ========================================================== config */

        public function config_fields($settings = []): array
        {
            return [
                'api-key' => [
                    'type'        => 'password',
                    'name'        => $this->lang['fields']['api-key'] ?? 'API Key',
                    'description' => $this->lang['fields']['api-key-desc'] ?? 'Your get.bd partner API key.',
                    'value'       => $settings['api-key'] ?? '',
                ],
                'test-mode' => [
                    'type'        => 'approval',
                    'name'        => $this->lang['fields']['test-mode'] ?? 'Development Mode',
                    'description' => $this->lang['fields']['test-mode-desc'] ?? 'Point the module at a self-hosted get.bd development API instead of production.',
                    'value'       => 1,
                    'checked'     => (bool) ($settings['test-mode'] ?? false),
                ],
                'base-url' => [
                    'type'        => 'text',
                    'name'        => $this->lang['fields']['base-url'] ?? 'API Base URL (v1)',
                    'description' => $this->lang['fields']['base-url-desc'] ?? 'Advanced. Default: https://api.get.bd/api/v1/external — use http://localhost:4000/api/v1/external for local development.',
                    'value'       => $settings['base-url'] ?? '',
                ],
                'v2-base-url' => [
                    'type'        => 'text',
                    'name'        => $this->lang['fields']['v2-base-url'] ?? 'API Base URL (v2)',
                    'description' => $this->lang['fields']['v2-base-url-desc'] ?? 'Advanced. Default: https://api.get.bd/api/v2/external.',
                    'value'       => $settings['v2-base-url'] ?? '',
                ],
                'send-v2-fields' => [
                    'type'        => 'approval',
                    'name'        => $this->lang['fields']['send-v2-fields'] ?? 'Send extended (v2) order fields',
                    'description' => $this->lang['fields']['send-v2-fields-desc'] ?? 'Include companyName / city / state / postcode / country in the create-order payload when provided.',
                    'value'       => 1,
                    'checked'     => (bool) ($settings['send-v2-fields'] ?? true),
                ],
                'default-country' => [
                    'type'        => 'text',
                    'name'        => $this->lang['fields']['default-country'] ?? 'Default country (ISO 2)',
                    'description' => $this->lang['fields']['default-country-desc'] ?? 'Used for the v2 "country" field when the client does not provide one.',
                    'value'       => $settings['default-country'] ?? 'BD',
                ],
                'cron-interval-minutes' => [
                    'type'        => 'text',
                    'name'        => $this->lang['fields']['cron-interval'] ?? 'Activation check interval (minutes)',
                    'description' => $this->lang['fields']['cron-interval-desc'] ?? 'How often the cron polls get.bd for activation of pending domains. Default: 10.',
                    'value'       => $settings['cron-interval-minutes'] ?? 10,
                ],
                'retry-process-hours' => [
                    'type'        => 'text',
                    'name'        => $this->lang['fields']['retry-process'] ?? 'Order process retry interval (hours)',
                    'description' => $this->lang['fields']['retry-process-desc'] ?? 'How often to re-call the process endpoint for submitted orders. Default: 6.',
                    'value'       => $settings['retry-process-hours'] ?? 6,
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
            if ($this->api) return;

            $settings = $this->settings();
            $key      = $this->decode_str((string) ($settings['api-key'] ?? ''));
            if ($key !== '') $settings['api-key'] = $key;

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
            if (!is_array($tlds)) $tlds = [$tlds];

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
            if ($info === false) return false;

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
                if ($ns !== '') $nameServers[] = $ns;
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
            if ($info === false) return false;

            $local  = $info['data']['localDomain'] ?? [];
            $expiry = substr((string) ($local['expiryDate'] ?? ($info['data']['expiryDate'] ?? '')), 0, 10);

            if ($expiry === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry)) {
                $this->error = 'No end date information was received.';
                return false;
            }

            return [
                'endtime' => $expiry . ' 00:00:00',
                'status'  => !empty($local['isActive']) ? 'active' : 'expired',
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
            if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');

            try {
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
                $service   = $serviceId ? \Services::get($serviceId) : false;

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
                $sendV2   = (bool) ($settings['send-v2-fields'] ?? true);

                $v2 = [
                    'companyName' => mb_substr($post('company_name'), 0, 120),
                    'city'        => mb_substr($post('city'), 0, 60),
                    'state'       => mb_substr($post('post_state'), 0, 60),
                    'postcode'    => mb_substr($post('postcode'), 0, 12),
                    'country'     => strtoupper(mb_substr(preg_replace('/[^A-Za-z]/', '', $post('country')), 0, 2)),
                ];
                if (!$sendV2) {
                    $v2 = array_map(static fn($v) => '', $v2);
                }
                if ($v2['country'] === '') {
                    $v2['country'] = strtoupper(substr((string) ($settings['default-country'] ?? 'BD'), 0, 2));
                }

                $nameServers = [];
                for ($i = 1; $i <= 3; $i++) {
                    $ns = strtolower($post('ns' . $i));
                    if ($ns === '') continue;
                    if (!preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $ns)) {
                        $this->respondJson('error', $this->lang['verify']['err-ns'] ?? 'Nameserver format is invalid.');
                    }
                    if (!in_array($ns, $nameServers, true)) $nameServers[] = $ns;
                }

                /* ---- receive + store uploaded documents ---- */

                $stored = $this->storeVerifyDocuments($serviceId);
                if (count($stored['files']) < 2) {
                    $this->respondJson('error', $this->lang['verify']['err-files'] ?? 'Please attach at least 2 document files.');
                }
                if ($stored['error'] !== '') {
                    $this->respondJson('error', $stored['error']);
                }

                /* ---- mark as submitting (guards double-submits) ---- */

                $options['getbd_state']        = 'submitting';
                $options['getbd_error']        = '';
                $options['getbd_submitted_at'] = \DateManager::Now();
                $this->options                 = $options;
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
                    'domainName'     => $domainAscii,
                    'years'          => $year,
                    'fullName'       => $fullName,
                    'nid'            => $nid,
                    'email'          => $email,
                    'contactAddress' => $address,
                    'contactNumber'  => $phone,
                ];
                if ($nameServers) {
                    $payload['nameServers'] = $nameServers;
                }
                foreach ($v2 as $field => $value) {
                    if ($value !== '') $payload[$field] = $value;
                }

                try {
                    $orderResponse = $this->api->createOrder($payload, 'wisecp-' . $serviceId);
                } catch (\Throwable $e) {
                    $this->options['getbd_state'] = 'awaiting_verification';
                    $this->options['getbd_error'] = mb_substr($e->getMessage(), 0, 250);
                    $this->save_options();
                    $this->save_log('verify.createOrder.failed', \Utility::jencode(['service' => $serviceId]), $e->getMessage());
                    $this->respondJson('error', $this->lang['verify']['err-order'] ?? 'The domain could not be reserved. Please try again or contact support.');
                }

                $orderId = (string) ($orderResponse['data']['id'] ?? '');
                if (empty($orderResponse['success']) || $orderId === '') {
                    $this->options['getbd_state'] = 'awaiting_verification';
                    $this->options['getbd_error'] = mb_substr((string) ($orderResponse['message'] ?? 'Order creation failed.'), 0, 250);
                    $this->save_options();
                    $this->respondJson('error', $this->lang['verify']['err-order'] ?? 'The domain could not be reserved. Please try again or contact support.');
                }

                /* ---- order exists: persist + upload docs + first process ---- */

                $this->options['getbd_order_id'] = $orderId;
                $this->options['getbd_docs']     = [
                    'form' => array_merge([
                        'fullName' => $fullName,
                        'nid'      => $nid,
                        'email'    => $email,
                        'phone'    => $phone,
                        'address'  => $address,
                    ], array_filter($v2, static fn($v) => $v !== '')),
                    'files' => $stored['files'],
                ];

                $uploadFailures = 0;
                foreach ($stored['files'] as $file) {
                    try {
                        $this->api->uploadDocument($orderId, (string) $file['path'], (string) $file['name']);
                    } catch (\Throwable $e) {
                        $uploadFailures++;
                        $this->save_log('verify.uploadDocument.failed', \Utility::jencode(['service' => $serviceId, 'file' => $file['name']]), $e->getMessage());
                    }
                }
                if ($uploadFailures) {
                    $this->options['getbd_error'] = $uploadFailures . ' document(s) could not be forwarded to get.bd automatically; upload them via the partner portal.';
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
                        }
                    }
                } catch (\Throwable $e) {
                    $message = $e->getMessage();
                    if (!str_contains($message, self::PROCESS_DOC_GATE_SNIPPET)) {
                        $this->options['getbd_error'] = mb_substr($message, 0, 250);
                        $this->save_log('verify.processOrder.failed', $orderId, $message);
                    }
                }
                $this->save_options();

                $this->save_log('verify.submitted', \Utility::jencode(['service' => $serviceId, 'order' => $orderId]), 'ok');

                $this->respondJson('success', $this->lang['verify']['done'] ?? 'Documents submitted. Your domain is now under BTCL review and will be activated automatically once approved.');
            } catch (\Throwable $e) {
                if ($this->responded) throw $e; // a response was already emitted
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

            $raw = $_FILES['doc_file'] ?? null;
            if (!is_array($raw) || !isset($raw['name']) || !is_array($raw['name'])) {
                return $result;
            }

            $count = count($raw['name']);
            if ($count > 4) {
                $result['error'] = $this->lang['verify']['err-files-count'] ?? 'A maximum of 4 document files is allowed.';
                return $result;
            }

            $allowed = explode(',', 'pdf,jpg,jpeg,png,gif,doc,docx,txt,zip');
            $folder  = rtrim((string) ROOT_DIR, "\\/") . DS . 'resources' . DS . 'uploads' . DS . 'documents' . DS . 'getbd' . DS . $serviceId . DS;
            if (!is_dir($folder)) @mkdir($folder, 0755, true);
            if (!is_dir($folder)) {
                $result['error'] = $this->lang['verify']['err-storage'] ?? 'Document storage is not writable. Please contact support.';
                return $result;
            }

            for ($i = 0; $i < $count; $i++) {
                if ((int) ($raw['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
                if ((int) ($raw['error'][$i] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                    $result['error'] = $this->lang['verify']['err-file-upload'] ?? 'A document could not be uploaded. Please try again.';
                    return $result;
                }

                $single = [
                    'name'     => $raw['name'][$i],
                    'type'     => $raw['type'][$i] ?? '',
                    'tmp_name' => $raw['tmp_name'][$i] ?? '',
                    'error'    => $raw['error'][$i],
                    'size'     => $raw['size'][$i] ?? 0,
                ];

                $ext = strtolower(pathinfo((string) $single['name'], PATHINFO_EXTENSION));
                if ($ext === '' || !in_array($ext, $allowed, true) || \Uploads::is_executable_ext($ext)) {
                    $result['error'] = $this->lang['verify']['err-file-type'] ?? 'Document type is not allowed (pdf, jpg, png, doc, docx, txt, zip).';
                    return $result;
                }
                if ((int) $single['size'] <= 0 || (int) $single['size'] > 5 * 1024 * 1024) {
                    $result['error'] = $this->lang['verify']['err-file-size'] ?? 'Each document must be between 0 and 5 MB.';
                    return $result;
                }

                $upload = new \Uploads($single, [
                    'folder'        => $folder,
                    'file-name'     => 'random',
                    'date'          => false,
                    'allowed-ext'   => implode(',', $allowed),
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
                    'name' => (string) ($op['file_name'] ?? $single['name']),
                    'path' => rtrim($folder, '\\/') . DS . (string) ($op['name'] ?? ''),
                    'size' => (int) ($op['size'] ?? 0),
                ];
            }

            return $result;
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
                $retryH   = max(1, (int) ($settings['retry-process-hours'] ?? 6));

                $query = \WDB::select('id, name, options, duedate')->from('users_products');
                $query->where('type', '=', 'domain', '&&');
                $query->where('module', '=', $this->_name, '&&');
                $query->where('status', '=', 'inprocess');
                $rows = $query->build() ? $query->getAssoc() : [];
                if (!is_array($rows)) return;

                foreach ($rows as $row) {
                    $serviceId = (int) ($row['id'] ?? 0);
                    if (!$serviceId) continue;

                    $options = \Utility::jdecode((string) ($row['options'] ?? ''), true);
                    if (!is_array($options)) $options = [];

                    $orderId = (string) ($options['getbd_order_id'] ?? '');
                    if ($orderId === '') continue;

                    $lastRun = isset($options['getbd_last_cron']) ? strtotime((string) $options['getbd_last_cron']) : false;
                    if ($lastRun && (time() - $lastRun) < $interval * 60) continue;

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
            if ($domain === false) return;

            try {
                $info = $this->api->getDomainInfo($domain);
            } catch (\Throwable $e) {
                $message = $e->getMessage();
                if (stripos($message, 'RESERVATION_EXPIRED') !== false || stripos($message, 'not found') !== false) {
                    $this->options['getbd_error'] = mb_substr($message, 0, 250);
                    $this->save_options();
                    $this->save_log('cron.reservationProblem', \Utility::jencode(['service' => $serviceId, 'order' => $orderId]), $message);
                }
                return;
            }

            $local = $info['data']['localDomain'] ?? [];

            if (!empty($local['isActive'])) {
                $expiry = substr((string) ($local['expiryDate'] ?? ($info['data']['expiryDate'] ?? '')), 0, 10);

                $this->options['getbd_state']          = 'active';
                $this->options['getbd_error']          = '';
                $this->options['getbd_activated_at']   = \DateManager::Now();
                $this->options['getbd_activation_date'] = $expiry;

                $update = ['options' => $this->options];
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry)) {
                    $update['duedate'] = $expiry;
                }
                \Services::set($serviceId, $update);
                \Services::change_status($serviceId, 'active', [
                    'module_completed' => true,
                    'module_action'    => 'register',
                ]);

                $this->save_log('cron.activated', \Utility::jencode(['service' => $serviceId, 'order' => $orderId, 'expiry' => $expiry]), 'ok');
                return;
            }

            // not active yet: periodically re-attempt processing so the order
            // completes as soon as BTCL approves the documents
            $lastRetry = isset($this->options['getbd_last_process_retry'])
                ? strtotime((string) $this->options['getbd_last_process_retry'])
                : false;
            if ($lastRetry && (time() - $lastRetry) < $retryHours * 3600) return;

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
                    }
                }
            } catch (\Throwable $e) {
                $message = $e->getMessage();
                if (!str_contains($message, self::PROCESS_DOC_GATE_SNIPPET)) {
                    $this->options['getbd_error'] = mb_substr($message, 0, 250);
                    $this->save_options();
                    $this->save_log('cron.processOrder.failed', $orderId, $message);
                }
            }
        }

        /* ================================================== client UI ==== */

        /** Per-TLD required document matrix (mirror of the WHMCS reference). */
        public static function docMatrix(): array
        {
            return [
                'com.bd' => [
                    'Trade License OR Certificate of Incorporation / Business Registration',
                    'Authorization letter (if applicant is not owner)',
                    'TIN certificate (optional but recommended)',
                ],
                'net.bd' => [
                    'Trade License OR Certificate of Incorporation / Business Registration',
                    'Authorization letter (if applicant is not owner)',
                    'TIN certificate (optional but recommended)',
                ],
                'org.bd' => [
                    'NGO Affairs Bureau certificate',
                    'Trust deed',
                    'Association registration certificate',
                ],
                'edu.bd' => [
                    'Government approval letter',
                    'Ministry of Education recognition',
                    'Education Board affiliation certificate',
                    'UGC approval',
                    'Institution registration certificate',
                ],
                'ac.bd' => [
                    'Government approval letter',
                    'Ministry of Education recognition',
                    'Education Board affiliation certificate',
                    'UGC approval',
                    'Institution registration certificate',
                ],
                'gov.bd' => [
                    'Official request letter',
                    'Ministry or departmental approval',
                    'Government order or gazette (if applicable)',
                ],
                'mil.bd' => [
                    'Official authorization from Bangladesh Army / Navy / Air Force',
                ],
                'info.bd' => [
                    'Basic identity or organization registration documents',
                    'Explanation of intended information usage (if required)',
                ],
                'id.bd' => [
                    'No specific documents required (NID verification only)',
                ],
                'biz.bd' => [
                    'Basic identity or business registration documents',
                ],
                'bd' => [
                    'NID verification (additional documents may be requested case-by-case)',
                ],
            ];
        }

        /**
         * Modal + JavaScript injected into the client area. The same payload
         * serves the domains list and the domain detail page; the JS wires up
         * whichever context it finds.
         */
        public static function clientAssets(): string
        {
            if (\defined('CRON')) return ''; // no client UI inside cron context

            $endpoint = rtrim(\Utility::AppAdress(), '/') . '/getbd-verify';
            $csrf     = \Validation::get_csrf_token('domains', true);
            $lang     = [
                'title'      => 'Verify to Active',
                'lead'       => 'Complete the required verification to activate your .bd domain.',
                'button'     => 'Verify to Active',
                'review'     => 'Under BTCL review',
                'documents'  => 'Required documents',
                'doc1'       => 'Document 1 (required)',
                'doc2'       => 'Document 2 (required)',
                'docMore'    => 'Additional document (optional)',
                'fullName'   => 'Registrant full name',
                'nid'        => 'NID number (10 / 13 / 17 digits)',
                'email'      => 'Email address',
                'phone'      => 'Contact number (+8801XXXXXXXXX)',
                'address'    => 'Contact address',
                'company'    => 'Company name (optional)',
                'city'       => 'City (optional)',
                'state'      => 'State / Division (optional)',
                'postcode'   => 'Postcode (optional)',
                'country'    => 'Country (optional, ISO code)',
                'ns'         => 'Nameserver :n (optional)',
                'submit'     => 'Submit documents',
                'submitting' => 'Submitting…',
                'note'       => 'BTCL reviews the documents manually; the domain activates automatically after approval. Orders reserve the domain for 7 days.',
                'errFiles'   => 'Please attach at least 2 document files.',
                'errPhone'   => 'Contact number must look like +8801XXXXXXXXX.',
                'errNid'     => 'NID number must be 10, 13, or 17 digits.',
                'done'       => 'Documents submitted — your domain is under BTCL review.',
                'failed'     => 'Submission failed:',
            ];

            $jsJson = fn($v): string => json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $docsJson     = $jsJson(self::docMatrix());
            $langJson     = $jsJson($lang);
            $endpointJson = $jsJson($endpoint);

            return <<<HTML
<div class="modal fade" id="getbdVerifyModal" tabindex="-1" aria-hidden="true">
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
                        <div class="col-md-6"><label class="form-label">{$lang['doc1']}</label><input type="file" class="form-control" name="doc_file[]" required accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx,.txt,.zip"></div>
                        <div class="col-md-6"><label class="form-label">{$lang['doc2']}</label><input type="file" class="form-control" name="doc_file[]" required accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx,.txt,.zip"></div>
                        <div class="col-12"><label class="form-label">{$lang['docMore']}</label><input type="file" class="form-control" name="doc_file[]" accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx,.txt,.zip"></div>
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
    var L = {$langJson};
    var DOCS = {$docsJson};
    var ENDPOINT = {$endpointJson};

    var BD_TLDS = ['bd','com.bd','net.bd','org.bd','edu.bd','gov.bd','ac.bd','mil.bd','info.bd','tv.bd','co.bd','ai.bd','sch.bd','id.bd','biz.bd'];

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

    function injectListButtons() {
        var rows = document.querySelectorAll('article[data-status="pending"][data-name]');
        rows.forEach(function (row) {
            var name = (row.getAttribute('data-name') || '').toLowerCase();
            if (!isBdDomain(name)) return;
            var actions = row.querySelector('.list-actions');
            if (!actions || actions.querySelector('.getbd-verify-btn')) return;

            var id = 0;
            var link = actions.querySelector('a[href*="domain-detail"]');
            if (link) {
                var m = link.getAttribute('href').match(/domain-detail[\/=](\d+)/);
                if (m) id = m[1];
            }
            if (!id) return;

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

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-primary btn-sm mt-2 getbd-verify-btn';
        btn.innerHTML = '<i class="bi bi-patch-check me-1"></i>' + L.button;
        btn.addEventListener('click', function () { openModal(id, name); });

        var host = document.querySelector('.sd-hero-id') || ctx;
        host.appendChild(btn);
    }

    function openModal(serviceId, domain) {
        var modalEl = document.getElementById('getbdVerifyModal');
        if (!modalEl || typeof bootstrap === 'undefined') return;

        modalEl.querySelector('#getbd-m-domain').textContent = domain;
        modalEl.querySelector('#getbd-service-id').value = serviceId;
        modalEl.querySelector('#getbd-alert').classList.add('d-none');

        var docList = modalEl.querySelector('#getbd-doc-list');
        var tld = tldOf(domain);
        var items = DOCS[tld] || DOCS['bd'] || [];
        docList.innerHTML = '';
        items.forEach(function (item) {
            var div = document.createElement('div');
            div.textContent = '- ' + item;
            docList.appendChild(div);
        });

        var form = modalEl.querySelector('#getbd-verify-form');
        form.reset();
        modalEl.querySelector('input[name="country"]').value = '';

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

        var files = form.querySelectorAll('input[type="file"]');
        var fileCount = 0;
        files.forEach(function (input) {
            fileCount += input.files.length;
        });
        if (fileCount < 2) return show(L.errFiles);

        var phone = form.querySelector('input[name="contact_number"]').value.replace(/[\\s\\-()]/g, '');
        if (phone.indexOf('+') !== 0 && phone.indexOf('880') === 0) phone = '+' + phone;
        if (phone.indexOf('+') !== 0 && phone.indexOf('0') === 0) phone = '+880' + phone.substring(1);
        if (!/^\\+8801[3-9]\\d{8}$/.test(phone)) return show(L.errPhone);
        form.querySelector('input[name="contact_number"]').value = phone;

        var fd = new FormData(form);
        btn.disabled = true;
        var original = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i>' + L.submitting;

        fetch(ENDPOINT, {
            method: 'POST',
            body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) { return r.json(); }).then(function (data) {
            if (data && data.status === 'success') {
                modalEl.querySelector('.modal-body').innerHTML =
                    '<div class="text-center py-4"><i class="bi bi-patch-check-fill text-success" style="font-size:2.5rem"></i>' +
                    '<p class="mt-3 mb-0">' + (data.message || L.done) + '</p></div>';
                modalEl.querySelector('.modal-footer').innerHTML = '';
                setTimeout(function () { window.location.reload(); }, 2500);
            } else {
                show((data && data.message) || L.failed);
            }
        }).catch(function () {
            show(L.failed);
        }).finally(function () {
            btn.disabled = false;
            btn.innerHTML = original;
        });

        function show(msg) {
            alertBox.textContent = L.failed + ' ' + msg;
            alertBox.classList.remove('d-none');
        }
    }

    function init() {
        injectListButtons();
        injectDetailButton();
        var submit = document.getElementById('getbd-submit');
        if (submit && !submit.dataset.getbdBound) {
            submit.dataset.getbdBound = '1';
            submit.addEventListener('click', submitForm);
        }
    }

    if (document.readyState !== 'loading') init();
    else document.addEventListener('DOMContentLoaded', init);
})();
</script>
HTML;
        }

        /* ==================================================== helpers ===== */

        private function asciiDomain(string $domain): string|false
        {
            $domain = strtolower(trim($domain));
            if ($domain === '') return false;
            $ascii = idn_to_ascii($domain, 0, INTL_IDNA_VARIANT_UTS46);
            if ($ascii === false || !str_contains($ascii, '.')) return false;
            return $ascii;
        }

        private function normalizePhone(string $raw): string|false
        {
            $phone = preg_replace('/[\s\-().]/', '', trim($raw));
            if ($phone === null || $phone === '') return false;
            if ($phone[0] !== '+') {
                if (str_starts_with($phone, '880')) $phone = '+' . $phone;
                elseif (str_starts_with($phone, '0')) $phone = '+880' . substr($phone, 1);
                else return false;
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

\Hook::add('ui:client.domains_list.modals.end', 1, fn (): string => GetBD::clientAssets());
\Hook::add('ui:client.domain_detail.modals.end', 1, fn (): string => GetBD::clientAssets());
