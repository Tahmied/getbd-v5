<?php
/**
 * WISECP — GetBD Domain Registrar Module
 *
 * Registrar module for .bd domains via the Get BD partner API
 * (https://api.get.bd/api/v1/external, sandbox: sandbox-api.get.bd).
 *
 * .bd domains are governed by BTCL rules: a registration requires the
 * registrant's NID and supporting documents, and the domain does not
 * become active at the registry until the documents are approved and the
 * order is processed. This module:
 *
 *   1. creates the Get BD order (+ attempts initial processing) on register,
 *   2. collects NID / registration type / verification documents through
 *      WiseCP's native doc-fields verification system ($this->docs),
 *   3. retries order processing once the documents are verified
 *      (hook: action:domain.verification_submitted + PerMinuteCronJob),
 *   4. reflects the real activation state through sync().
 */

    namespace WISECP\Modules\Registrars;

    use RegistrarModule;
    use WISECP\Modules\Registrars\GetBD\ApiClient;

    class GetBD extends RegistrarModule
    {
        /** get.bd accepts at most 3 nameservers per domain. */
        private const MAX_NAMESERVERS = 3;

        /** Expected API message while the order still lacks approved documents. */
        private const DOCS_PENDING_MSG = 'APPROVED documents';

        /** Minutes between processing retries per service (cron safety net). */
        private const DOC_CHECK_INTERVAL = 15;

        private function initApi(): void
        {
            if ($this->api) return;

            include_once __DIR__ . DS . 'ApiClient.php';

            $settings = $this->config['settings'] ?? [];
            if ($key = $this->decode_str($settings['api-key'] ?? ''))           $settings['api-key'] = $key;
            if ($key = $this->decode_str($settings['api-key-sandbox'] ?? ''))   $settings['api-key-sandbox'] = $key;

            $this->api = new ApiClient($settings);
            $this->api->logger = fn($action, $req, $resp, $processed = '') => $this->save_log($action, $req, $resp, $processed);
        }


        public function config_fields($settings = []): array
        {
            $sandbox = !empty($settings['test-mode']);
            $toggle  = "var d=this.closest('form')||document;"
                     . "d.querySelectorAll('.sandbox-row').forEach(function(r){r.classList.toggle('d-none',!this.checked);}.bind(this));"
                     . "d.querySelectorAll('.not-sandbox-row').forEach(function(r){r.classList.toggle('d-none',this.checked);}.bind(this));";

            return [
                'test-mode' => [
                    'type'         => 'approval',
                    'name'         => $this->lang['fields']['test-mode'] ?? 'Sandbox Mode',
                    'description'  => $this->lang['desc']['test-mode'] ?? 'Use sandbox-api.get.bd instead of the live API.',
                    'value'        => 1,
                    'checked'      => $sandbox,
                    'fieldOptions' => ['attributes' => ['onchange' => $toggle]],
                ],
                'api-key' => [
                    'type'         => 'password',
                    'name'         => $this->lang['fields']['api-key'] ?? 'API Key',
                    'description'  => $this->lang['desc']['api-key'] ?? 'Get BD partner API key (live mode).',
                    'value'        => $settings['api-key'] ?? '',
                    'fieldOptions' => ['rowClassExtra' => ($sandbox ? 'd-none ' : '') . 'not-sandbox-row'],
                ],
                'api-key-sandbox' => [
                    'type'         => 'password',
                    'name'         => $this->lang['fields']['api-key-sandbox'] ?? 'Sandbox API Key',
                    'description'  => $this->lang['desc']['api-key-sandbox'] ?? 'Get BD partner API key (sandbox mode).',
                    'value'        => $settings['api-key-sandbox'] ?? '',
                    'fieldOptions' => ['rowClassExtra' => ($sandbox ? '' : 'd-none ') . 'sandbox-row'],
                ],
            ];
        }

        public function testConnection($config = []): bool
        {
            $settings = $config['settings'] ?? [];
            $key      = trim((string) ($settings['api-key'] ?? ''));
            $skey     = trim((string) ($settings['api-key-sandbox'] ?? ''));

            if ((!empty($settings['test-mode']) && $skey === '') || (empty($settings['test-mode']) && $key === '')) {
                $this->error = $this->lang['error6'] ?? 'Please enter the API information.';
                return false;
            }

            include_once __DIR__ . DS . 'ApiClient.php';
            $api = new ApiClient($settings);

            // Probe a real endpoint — the availability search answers with a
            // proper envelope for any valid domain name, existing or not.
            $api->get('/domains/search', ['domain' => 'getbd-connection-check.com.bd']);

            if ($api->error) {
                if ($api->lastHttpCode === 401 || $api->lastHttpCode === 403)
                    $this->error = $this->lang['error-invalid-key'] ?? 'API Key is invalid.';
                else
                    $this->error = $api->error;
                return false;
            }

            return true;
        }


        public function check($sld = null, $tlds = []): array
        {
            if ($sld == '' || !$tlds) {
                $this->error = $this->lang['error2'] ?? 'Domain and extension information is missing.';
                return [];
            }

            if (!is_array($tlds)) $tlds = [$tlds];

            $this->initApi();
            $sld = idn_to_ascii((string) $sld, 0, INTL_IDNA_VARIANT_UTS46);

            $result = [];
            $failed = '';

            foreach ($tlds as $tld) {
                $response = $this->api->get('/domains/search', ['domain' => $sld . '.' . $tld]);

                if (!$response) {
                    $failed = $this->api->error ?: $failed;
                    continue;
                }

                $data = $response['data'] ?? [];

                if (isset($data['available']))
                    $result[$tld] = ['status' => !empty($data['available']) ? 'available' : 'unavailable'];
                elseif (!empty($response['success']))
                    $result[$tld] = ['status' => 'unavailable'];
                else
                    $failed = (string) ($response['message'] ?? $failed);
            }

            if (!$result) {
                $this->error = $failed ?: 'Domain availability check failed';
                return [];
            }

            return $result;
        }


        public function register(): array|bool
        {
            $this->error = null;
            $this->initApi();
            [$domain] = $this->domain_parts();

            if (!$domain) {
                $this->error = 'Domain information is missing.';
                return false;
            }

            // Idempotency: if the domain already exists at the registry, don't create a second order.
            $existing = $this->getDomainInfoRaw($domain);
            if (is_array($existing) && !empty($existing['success']) && !empty($existing['data'])) {
                $this->error = $domain . ' already exists at the .bd registry.';
                return false;
            }

            $year = (int) ($this->options['year'] ?? $this->service['period_time'] ?? 1) ?: 1;
            $docs = is_array($this->docs) ? $this->docs : [];

            // First pass (right after checkout): the NID has not been submitted
            // yet. WiseCP only unlocks the client-area verification upload for
            // ACTIVE services, so report success WITHOUT creating an order —
            // the service goes active, the client submits NID + documents, and
            // the verification_submitted hook re-queues register().
            $nidRaw        = trim((string) ($docs['nid'] ?? ''));
            $nid           = $this->normalize_nid($nidRaw);
            $nidSubmitted  = $nidRaw !== '';

            if ($nid === '') {
                if (!$nidSubmitted) {
                    return ['status' => 'active', 'config' => ['awaiting_docs' => 1]];
                }

                $this->error = $this->lang['error-nid-invalid']
                    ?? 'The submitted NID number is invalid — it must be 10, 13 or 17 digits. Please correct it and resubmit.';
                return false;
            }

            $reg = is_array($this->options['whois'] ?? null)
                ? ($this->options['whois']['registrant'] ?? $this->options['whois'])
                : [];

            $fullName = trim(trim((string) ($reg['FirstName'] ?? '') . ' ' . ($reg['LastName'] ?? '')) ?: (string) ($reg['Name'] ?? '') ?: (string) ($this->user['full_name'] ?? ''));
            $email    = trim((string) ($reg['EMail'] ?? '') ?: (string) ($this->user['email'] ?? ''));
            $address  = trim(implode(', ', array_filter([
                (string) ($reg['AddressLine1'] ?? ''),
                (string) ($reg['AddressLine2'] ?? ''),
                (string) ($reg['City'] ?? ''),
                (string) ($reg['State'] ?? ''),
                (string) ($reg['ZipCode'] ?? ''),
                (string) ($reg['Country'] ?? ''),
            ])));
            $contact  = $this->normalize_bd_phone(
                (string) ($reg['PhoneCountryCode'] ?? '') . (string) ($reg['Phone'] ?? ''),
                (string) ($this->user['gsm_cc'] ?? '') . (string) ($this->user['gsm_number'] ?? ''),
                (string) ($this->user['phone'] ?? '')
            );

            if ($fullName === '' || $email === '' || $contact === '') {
                $this->error = 'Registrant name, e-mail and a valid Bangladeshi contact number (+880) are required.';
                return false;
            }

            $nameservers = $this->clean_nameservers(
                is_array($this->options['dns'] ?? null) ? $this->options['dns'] : []
            );

            $orderResponse = $this->api->post('/orders', [
                'domainName'     => $domain,
                'years'          => $year,
                'fullName'       => $fullName,
                'nid'            => $nid,
                'email'          => $email,
                'contactAddress' => $address,
                'contactNumber'  => $contact,
                'nameServers'    => $nameservers,
            ]);

            if (!is_array($orderResponse) || empty($orderResponse['success']) || empty($orderResponse['data']['id'])) {
                $this->error = (string) ($orderResponse['message'] ?? $this->api->error ?? 'Order creation failed');
                return false;
            }

            $orderId = (string) $orderResponse['data']['id'];

            // The .bd registry only processes the order once at least 2 documents
            // are APPROVED. Until then the expected "documents pending" failure is
            // not an error: the order exists and processing is retried by the cron.
            $processResponse = $this->api->post('/orders/' . rawurlencode($orderId) . '/process');
            if (is_array($processResponse) && empty($processResponse['success'])) {
                $message = (string) ($processResponse['message'] ?? '');
                if ($message !== '' && stripos($message, self::DOCS_PENDING_MSG) === false) {
                    // Order exists — keep the result so the queue doesn't create
                    // duplicates, but surface the message in the module log.
                    $this->save_log('register-notice', ['orderId' => $orderId], $message, $message);
                }
            }

            // Put the service back to PENDING while BTCL reviews the documents.
            // The module cron polls the registry and flips it to active (with the
            // real expiry date) as soon as the domain goes live.
            return [
                'status' => 'inprocess',
                'config' => [
                    'id'            => $orderId,
                    'creation_info' => $orderResponse['data'] ?? [],
                ],
            ];
        }

        public function transfer(): array|bool
        {
            $this->error = $this->lang['error-transfer-unsupported']
                ?? 'Domain transfer is not supported for .bd domains. Please process transfers manually.';
            return false;
        }

        public function renew(): array|bool
        {
            $this->initApi();
            [$domain] = $this->domain_parts();
            $year = (int) ($this->options['year'] ?? $this->service['period_time'] ?? 1) ?: 1;

            $response = $this->api->post('/domains/renew', [
                'domain' => $domain,
                'years'  => $year,
            ]);

            if (!is_array($response) || empty($response['success'])) {
                $this->error = (string) ($response['message'] ?? $this->api->error ?? 'Domain renewal failed');
                return false;
            }

            return true;
        }


        public function suspend(): bool   { return true; }
        public function unsuspend(): bool { return true; }

        public function cancel(): bool
        {
            // The Get BD API exposes no deletion endpoint; deletion is handled
            // directly at the registry by the provider.
            return true;
        }


        public function get_nameservers(): array|false
        {
            $info = $this->getDomainInfoForService();
            if ($info === false) return false;

            $data = $info['data'] ?? [];

            return [
                'ns1' => (string) ($data['primaryDns'] ?? ''),
                'ns2' => (string) ($data['secondaryDns'] ?? ''),
                'ns3' => (string) ($data['tertiaryDns'] ?? ''),
            ];
        }

        public function save_nameservers(array $dns): bool
        {
            $this->initApi();
            [$domain] = $this->domain_parts();

            $nameservers = $this->clean_nameservers(array_values($dns));

            $response = $this->api->put('/domains/update', [
                'domain'      => $domain,
                'nameServers' => $nameservers,
            ]);

            if (!is_array($response) || empty($response['success'])) {
                $this->error = (string) ($response['message'] ?? $this->api->error ?? 'Nameserver update failed');
                return false;
            }

            return true;
        }


        public function is_inactive(): bool
        {
            $info = $this->getDomainInfoForService();
            if ($info === false) return false;

            return empty($info['data']['localDomain']['isActive']);
        }

        public function sync(): array|false
        {
            $info = $this->getDomainInfoForService();
            if ($info === false) return false;

            $data  = $info['data'] ?? [];
            $local = $data['localDomain'] ?? [];

            $end   = $this->normalize_date((string) ($local['expiryDate'] ?? ''));
            $start = $this->normalize_date((string) ($data['activationDate'] ?? ''));

            if (!empty($local['isActive']))
                $status = 'active';
            elseif ($end !== '' && strtotime($end) < time())
                $status = 'expired';
            else
                $status = 'unknown'; // registered, waiting on BTCL document approval

            return array_filter([
                'creationtime' => $start !== '' ? $start . ' 00:00:00' : '',
                'endtime'      => $end !== '' ? $end . ' 00:00:00' : '',
                'status'       => $status,
            ]);
        }

        public function transfer_sync(): array|false
        {
            // Transfers are not supported for .bd; keep the service pending
            // instead of failing the transfer event outright.
            return ['status' => 'pending'];
        }


        public function get_info(array $params = []): array|false
        {
            $this->initApi();
            $domain = idn_to_ascii(
                (string) ($params['domain'] ?? $this->options['domain'] ?? $this->service['name'] ?? ''),
                0,
                INTL_IDNA_VARIANT_UTS46
            );

            $info = $this->getDomainInfoRaw($domain);
            if (!is_array($info) || empty($info['success']) || empty($info['data'])) {
                $this->error = $this->error ?: (string) ($info['message'] ?? $this->api->error ?? 'Unable to retrieve domain information');
                return false;
            }

            $data  = $info['data'];
            $local = $data['localDomain'] ?? [];

            $result = [
                'creation_time' => $this->normalize_date((string) ($data['activationDate'] ?? '')),
                'end_time'      => $this->normalize_date((string) ($local['expiryDate'] ?? $data['expiryDate'] ?? '')),
                'transferlock'  => false,
            ];

            foreach ([1 => 'primaryDns', 2 => 'secondaryDns', 3 => 'tertiaryDns'] as $i => $key)
                if (!empty($data[$key])) $result['ns' . $i] = (string) $data[$key];

            if (!empty($data['clientFullName']) || !empty($data['clientEmail'])) {
                $result['whois'] = [
                    'registrant' => [
                        'Name'      => (string) ($data['clientFullName'] ?? ''),
                        'Company'   => '',
                        'EMail'     => (string) ($data['clientEmail'] ?? ''),
                        'Phone'     => (string) ($data['clientContactNumber'] ?? ''),
                        'PhoneCountryCode' => '',
                    ],
                ];
            }

            return $result;
        }

        public function domains()
        {
            $this->initApi();

            $response = $this->api->get('/domains');
            if (!$response && $this->api->error) {
                $this->error = $this->api->error;
                return false;
            }

            $list = $response['data'] ?? [];
            if (isset($list['items']) && is_array($list['items']))       $list = $list['items'];
            elseif (isset($list['domains']) && is_array($list['domains'])) $list = $list['domains'];
            elseif (isset($list['list']) && is_array($list['list']))     $list = $list['list'];
            if (!is_array($list)) $list = [];

            $result = [];
            foreach ($list as $item) {
                if (!is_array($item)) continue;

                $domain = strtolower((string) ($item['domain'] ?? $item['domainName'] ?? ''));
                if ($domain === '') continue;
                $domain = idn_to_utf8($domain, 0, INTL_IDNA_VARIANT_UTS46) ?: $domain;

                $local = $item['localDomain'] ?? [];

                $order_id  = 0;
                $user_data = [];
                $isImported = \WDB::select('id,owner_id AS user_id')->from('users_products');
                $isImported->where('type', '=', 'domain', '&&');
                $isImported->where('name', '=', $domain);
                $isImported = $isImported->build() ? $isImported->getAssoc() : false;
                if ($isImported) {
                    $order_id  = (int) $isImported['id'];
                    $user_data = \User::getData((int) $isImported['user_id'], 'id,full_name,company_name', 'array') ?: [];
                }

                $result[] = [
                    'domain'        => $domain,
                    'creation_date' => $this->normalize_date((string) ($item['activationDate'] ?? '')),
                    'end_date'      => $this->normalize_date((string) ($local['expiryDate'] ?? $item['expiryDate'] ?? '')),
                    'order_id'      => $order_id,
                    'user_data'     => $user_data,
                ];
            }

            return $result;
        }

        // NOTE: get_auth_code() is deliberately NOT implemented — the .bd
        // registry has no transfer codes. WiseCP detects capabilities by
        // method presence, so omitting the method hides every EPP/transfer
        // option in the client and admin areas.


        /**
         * Cron worker (PerMinuteCronJob hook). Three jobs:
         *
         * A) The client has not submitted the verification documents yet
         *    (service active with options.config.awaiting_docs) — nag the
         *    admin once after two days. Once the documents arrive, the
         *    verification_submitted hook re-queues register(); the cron is
         *    the hourly safety net.
         *
         * B) The order exists at Get BD and the service is pending — poll the
         *    registry (throttled per service). Once the domain went live
         *    (BTCL approved the documents and processed the order), flip the
         *    service to active with the real expiry date immediately instead
         *    of waiting for WiseCP's daily DomainStatusSync. Until then,
         *    once the doc-fields are verified, retry order processing.
         */
        public function document_queue_process(): void
        {
            $rows = \WDB::select('id, name, status, duedate, cdate, options')->from('users_products');
            $rows->where('type', '=', 'domain', '&&');
            $rows->where('module', '=', $this->_name, '&&');
            $rows->where('status', 'IN', ['inprocess', 'active']);
            $rows = $rows->build() ? \WDB::fetch_assoc() : [];

            if (!$rows) return;

            $docFields = $this->config['settings']['doc-fields'] ?? [];

            foreach ($rows as $row) {
                $serviceId = (int) ($row['id'] ?? 0);
                if (!$serviceId) continue;

                $options = \Utility::jdecode((string) ($row['options'] ?? ''), true) ?: [];

                // Activation already reflected locally — nothing left to do.
                if (!empty($options['getbd_activated'])) continue;

                $domain = strtolower((string) ($options['domain'] ?? $row['name'] ?? ''));
                $tld     = strpos($domain, '.') !== false ? substr($domain, strpos($domain, '.') + 1) : '';

                $required = [];
                foreach (((array) ($docFields[$tld] ?? [])) as $key => $field)
                    if (is_array($field) && ($field['required'] ?? false)) $required[] = (string) $key;
                if (!$required) continue;

                $stmt   = \WDB::select('doc_id, status')->from('users_products_docs')
                    ->where('owner_id', '=', $serviceId)
                    ->order_by('id ASC');
                $latest = [];
                if ($stmt->build()) foreach (\WDB::fetch_assoc() as $doc)
                    $latest[(string) ($doc['doc_id'] ?? '')] = (string) ($doc['status'] ?? 'unsent');

                $allPresent  = true; // submitted (pending or verified)
                $allVerified = true; // approved by admin
                foreach ($required as $key) {
                    $status = $latest['mod_' . $key] ?? 'unsent';
                    if (!in_array($status, ['pending', 'verified'], true)) $allPresent = false;
                    if ($status !== 'verified') $allVerified = false;
                }

                $orderId = (string) ($options['config']['id'] ?? '');

                // A) No order yet.
                if ($orderId === '') {
                    if (empty($options['config']['awaiting_docs'])) {
                        // Pre-existing domain (imported or registered outside
                        // this flow): it will never get a WiseCP-driven order,
                        // so mark its doc-fields verified and stop polling —
                        // this removes the "Verify" prompt from the client area.
                        if (($row['status'] ?? '') === 'active' && $this->doc_check_due($options)) {
                            $this->initApi();
                            $info      = $this->getDomainInfoRaw($domain);
                            $known     = is_array($info) && !empty($info['success']);
                            $notFound  = !$known && $this->api->lastHttpCode === 404;

                            if ($known || $notFound) {
                                $this->autoverify_docs(
                                    $serviceId,
                                    $required,
                                    (array) ($docFields[$tld] ?? []),
                                    $latest,
                                    (string) ($info['data']['clientNid'] ?? '')
                                );
                                $options['getbd_activated'] = 1;
                            }

                            $options['getbd_doc_check'] = \DateManager::Now();
                            \Services::set($serviceId, ['options' => $options]);
                        }
                        continue;
                    }

                    // Awaiting the client's documents.
                    $this->nag_unsubmitted_docs($serviceId, (string) ($row['name'] ?? ''), $row, $allPresent, $options);
                    if (!$allPresent) continue;

                    // Documents arrived — re-queue the registration (safety net;
                    // the verification_submitted hook normally does this first).
                    if ($this->requeue_due($options)) {
                        \ModuleQueue::add([
                            'module_type' => 'Registrar',
                            'module_name' => $this->_name,
                            'action'      => 'register',
                            'service_id'  => $serviceId,
                        ]);

                        $options['getbd_reg_requeue'] = \DateManager::Now();
                        \Services::set($serviceId, ['options' => $options]);
                    }
                    continue;
                }

                if (!$this->doc_check_due($options)) continue;

                $this->initApi();
                $info  = $this->getDomainInfoRaw($domain);
                $local = is_array($info) ? ($info['data']['localDomain'] ?? []) : [];

                if (!empty($local['isActive'])) {
                    // BTCL approved and activated the domain — flip the service
                    // to active (client gets the activation notification), write
                    // the real expiry date, then stop polling.
                    \Services::change_status($serviceId, 'active', [
                        'module_completed' => true,
                        'module_action'    => 'register',
                        'notify'           => true,
                    ]);

                    $expiry  = $this->normalize_date((string) ($local['expiryDate'] ?? ''));
                    $options = array_merge($options, ['getbd_activated' => 1]);
                    $update  = ['options' => $options];
                    if ($expiry !== '') $update['duedate'] = $expiry . ' 00:00:00';

                    \Services::set($serviceId, $update);
                    continue;
                }

                if ($allVerified)
                    $this->api->post('/orders/' . rawurlencode($orderId) . '/process');

                $options['getbd_doc_check'] = \DateManager::Now();
                \Services::set($serviceId, ['options' => $options]);
            }
        }

        /**
         * Marks the module's required doc-fields as verified for a domain that
         * never went through this module's order flow (imported / pre-existing).
         * WiseCP computes the client-area verification state from these rows,
         * so the "Verify" prompt disappears. The real NID is taken from the
         * registry when available.
         */
        private function autoverify_docs(int $serviceId, array $required, array $docFieldsTld, array $latest, string $nidValue): void
        {
            $cryptKey = (string) \Config::get('crypt/user');
            $now      = \DateManager::Now();

            foreach ($required as $key) {
                if (isset($latest['mod_' . $key])) continue; // already has a doc row

                $value = ($key === 'nid' && $nidValue !== '')
                    ? $nidValue
                    : 'Pre-existing domain — verified at the .bd registry';

                \WDB::insert('users_products_docs', [
                    'owner_id'    => $serviceId,
                    'doc_id'      => 'mod_' . $key,
                    'name'        => (string) ($docFieldsTld[$key]['name'] ?? $key),
                    'value'       => \Crypt::encode($value, $cryptKey),
                    'module_data' => \Crypt::encode(\Utility::jencode(['key' => $key, 'value' => $value]) ?: '', $cryptKey),
                    'file'        => '',
                    'status'      => 'verified',
                    'status_msg'  => 'Existing domain — no WiseCP verification required.',
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]);
            }
        }

        /**
         * One-time admin reminder when the client has not submitted the
         * required documents within two days of ordering.
         */
        private function nag_unsubmitted_docs(int $serviceId, string $domain, array $row, bool $allPresent, array $options): void
        {
            if ($allPresent || !empty($options['getbd_docs_nagged'])) return;

            $created = strtotime((string) ($row['cdate'] ?? ''));
            if (!$created || (time() - $created) < 2 * 86400) return;

            try {
                \Notification::dispatch('admin-messages', 'failed-service-activation', [
                    'entity'        => $row,
                    'error_message' => 'GetBD: the client has not submitted the required NID/documents for ' . $domain . ' — the domain is awaiting verification.',
                ]);
            } catch (\Throwable $e) {
            }

            $options['getbd_docs_nagged'] = 1;
            \Services::set($serviceId, ['options' => $options]);
        }

        private function doc_check_due(array $options): bool
        {
            $last = strtotime((string) ($options['getbd_doc_check'] ?? ''));
            if (!$last) return true;

            return (time() - $last) >= (self::DOC_CHECK_INTERVAL * 60);
        }

        private function requeue_due(array $options): bool
        {
            $last = strtotime((string) ($options['getbd_reg_requeue'] ?? ''));
            if (!$last) return true;

            return (time() - $last) >= 3600;
        }


        private function getDomainInfoForService()
        {
            $this->initApi();
            [$domain] = $this->domain_parts();

            $info = $this->getDomainInfoRaw($domain);
            if (!is_array($info) || empty($info['success']) || empty($info['data'])) {
                $this->error = $this->error ?: (string) ($info['message'] ?? $this->api->error ?? 'Unable to retrieve domain information');
                return false;
            }

            return $info;
        }

        private function getDomainInfoRaw(string $domain)
        {
            return $this->api->get('/domains/info', ['domain' => $domain]);
        }

        private function domain_parts(): array
        {
            $sld    = (string) ($this->options['sld'] ?? $this->options['name'] ?? '');
            $tld    = (string) ($this->options['tld'] ?? '');
            $domain = (string) ($this->options['domain'] ?? ($sld && $tld ? $sld . '.' . $tld : ($this->service['name'] ?? '')));

            return [
                idn_to_ascii($domain, 0, INTL_IDNA_VARIANT_UTS46) ?: strtolower(trim($domain)),
                idn_to_ascii($sld, 0, INTL_IDNA_VARIANT_UTS46) ?: strtolower(trim($sld)),
                strtolower(trim($tld, '.')),
            ];
        }

        private function clean_nameservers(array $list): array
        {
            $nameservers = [];
            foreach ($list as $ns) {
                $ns = trim((string) $ns);
                if ($ns !== '') $nameservers[] = $ns;
            }

            return array_slice($nameservers, 0, self::MAX_NAMESERVERS);
        }

        private function normalize_nid(string $nid): string
        {
            $nid = preg_replace('/\D+/', '', $nid) ?? '';
            if ($nid === '') return '';
            if (!in_array(strlen($nid), [10, 13, 17], true)) return '';

            return $nid;
        }

        private function normalize_bd_phone(string ...$candidates): string
        {
            foreach ($candidates as $candidate) {
                $digits = preg_replace('/\D+/', '', $candidate) ?? '';
                if ($digits === '') continue;

                if (strpos($digits, '880') === 0) $digits = substr($digits, 3);
                if (strpos($digits, '0') === 0)   $digits = substr($digits, 1);
                $digits = substr($digits, 0, 10);

                if (strlen($digits) < 10) continue;

                return '+880' . $digits;
            }

            return '';
        }

        private function normalize_date(string $date): string
        {
            if ($date === '') return '';
            return substr($date, 0, 10);
        }
    }


    /**
     * Hook: the client submitted verification documents — if registration was
     * waiting on them, queue it immediately (the cron is the safety net).
     */
    \Hook::add('action:domain.verification_submitted', 1, function ($service, $submitted, $defs) {
        if (!is_array($service)) return;
        if (($service['module'] ?? '') !== 'GetBD' || ($service['type'] ?? '') !== 'domain') return;
        if (($service['status'] ?? '') !== 'inprocess') return;

        \ModuleQueue::add([
            'module_type' => 'Registrar',
            'module_name' => 'GetBD',
            'action'      => 'register',
            'service_id'  => (int) ($service['id'] ?? 0),
        ]);
    });
    /**
     * UI hook: the .bd registry accepts at most 3 nameservers — hide the
     * 4th nameserver input on the client-area domain detail page, but only
     * for domains managed by this module.
     */
    \Hook::add('ui:client.domain_detail.nameservers.bottom', 1, function () {
        $serviceId = (int) (\Filter::init('REQUEST.id', 'rnumbers') ?: ($_REQUEST['id'] ?? 0));
        if (!$serviceId) return '';

        $stmt = \WDB::select('module')->from('users_products')
            ->where('id', '=', $serviceId, '&&')
            ->where('type', '=', 'domain');
        if (!$stmt->build()) return '';

        $row = \WDB::getAssoc();
        if (($row['module'] ?? '') !== 'GetBD') return '';

        return '<script>(function(){var h=function(){var el=document.getElementById("ddNs4");if(!el)return;var b=el.closest(".col-sm-6")||el.parentElement;if(b)b.style.display="none";};if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",h);else h();})();</script>';
    });

    /**
     * Cron safety net: re-queue registrations blocked on documents and retry
     * order processing once documents are verified.
     */
    \Hook::add('PerMinuteCronJob', 1, function () {
        \Modules::Load('Registrars', 'GetBD');
        $module = new GetBD();
        $module->document_queue_process();
    });
