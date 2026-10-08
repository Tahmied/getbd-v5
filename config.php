<?php

/**
 * GetBD module configuration.
 *
 * Keep this file STATIC: WiseCP rewrites it (array export) on every
 * module-settings save. All defaults below mirror config_fields() in
 * GetBD.php; WiseCP merges the admin-saved values over these.
 */

    return array (
        'meta' =>
        array (
            'name' => 'GetBD',
            'version' => '1.0.0',
            'logo' => 'logo.png',
            'description' => '.bd domain registration through the get.bd registry API (verify-to-active flow).',
        ),
        'settings' =>
        array (
            'whois-types' => true,
            'dns-record-types' =>
            array (
                0 => 'A',
                1 => 'AAAA',
                2 => 'CNAME',
                3 => 'MX',
                4 => 'NS',
                5 => 'TXT',
            ),
            'cost-currency' => 4,
            'api-key' => '',
            'test-mode' => false,
            'base-url' => '',
            'v2-base-url' => '',
            'send-v2-fields' => true,
            'default-country' => 'BD',
            'cron-interval-minutes' => 10,
            'retry-process-hours' => 6,
        ),
    );
