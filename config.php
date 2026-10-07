<?php
/**
 * GetBD Registrar Module for WiseCP — Configuration
 *
 * 'meta'    : module identity shown in the WiseCP admin area.
 * 'settings': saved/loaded by the WiseCP core. 'doc-fields' are the
 *             per-TLD verification fields WiseCP collects from the client
 *             in the domain manager (users_products_docs) and hands to the
 *             module via $this->docs.
 *
 * Per-TLD verification requirements (BTCL / get.bd):
 *
 *   .bd       NID or passport
 *   .com.bd   Trade licence + NID
 *   .net.bd   NID or trade licence
 *   .org.bd   Registration certificate (+ applicant NID for the API order)
 *   .edu.bd   EIIN or UGC approval (+ applicant NID for the API order)
 *   .info.bd  NID or trade licence
 *   .id.bd    NID or passport
 *   .sch.bd   EIIN certificate (+ applicant NID for the API order)
 *   .co.bd    Trade licence + NID
 *   .ai.bd    NID or trade licence
 *   .tv.bd    NID or trade licence
 *
 * The applicant NID is collected for every TLD because the get.bd order API
 * requires an `nid` value for every order; where the registry accepts a
 * trade licence instead, the field label reflects that.
 */

    $nidField = fn(string $label, string $tld) => [
        'type'        => 'text',
        'required'    => true,
        'name'        => $label,
        'description' => "Required for {$tld} verification with the .bd registry (BTCL).",
    ];

    $docsField = fn(bool $required, string $name, string $description) => [
        'type'          => 'file',
        'required'      => $required,
        'name'          => $name,
        'description'   => $description,
        'allowed_ext'   => 'pdf,jpg,jpeg,png',
        'max_file_size' => 10,
    ];

    $docFields = [];

    foreach ([
        'bd' => [
            'nid'  => 'NID or Passport Number',
            'docs' => $docsField(true, 'NID or Passport Copy', 'Upload a copy of the applicant\'s NID or passport. Required for .bd verification.'),
        ],
        'com.bd' => [
            'nid'  => 'NID Number',
            'docs' => $docsField(true, 'Trade Licence Copy', 'Upload a copy of the trade licence. Commercial .com.bd domains are for registered businesses only.'),
        ],
        'net.bd' => [
            'nid'  => 'NID or Trade Licence Number',
            'docs' => $docsField(false, 'Trade Licence Copy', 'Only required if registering with a trade licence instead of an NID.'),
        ],
        'org.bd' => [
            'nid'  => 'Applicant NID Number',
            'docs' => $docsField(true, 'Registration Certificate Copy', 'Upload the organisation\'s registration certificate. .org.bd domains are for registered organisations and non-profits.'),
        ],
        'edu.bd' => [
            'nid'  => 'Applicant NID Number',
            'docs' => $docsField(true, 'EIIN or UGC Approval Document', 'Upload the EIIN certificate or UGC approval. .edu.bd domains are for recognised educational institutions only.'),
        ],
        'info.bd' => [
            'nid'  => 'NID or Trade Licence Number',
            'docs' => $docsField(false, 'Trade Licence Copy', 'Only required if registering with a trade licence instead of an NID.'),
        ],
        'id.bd' => [
            'nid'  => 'NID or Passport Number',
            'docs' => $docsField(true, 'NID or Passport Copy', 'Upload a copy of the applicant\'s NID or passport. .id.bd domains are for personal identity use.'),
        ],
        'sch.bd' => [
            'nid'  => 'Applicant NID Number',
            'docs' => $docsField(true, 'EIIN Certificate Copy', 'Upload the school\'s EIIN certificate. .sch.bd domains are for schools only.'),
        ],
        'co.bd' => [
            'nid'  => 'NID Number',
            'docs' => $docsField(true, 'Trade Licence Copy', 'Upload a copy of the trade licence. .co.bd domains are for registered businesses only.'),
        ],
        'ai.bd' => [
            'nid'  => 'NID or Trade Licence Number',
            'docs' => $docsField(false, 'Trade Licence Copy', 'Only required if registering with a trade licence instead of an NID.'),
        ],
        'tv.bd' => [
            'nid'  => 'NID or Trade Licence Number',
            'docs' => $docsField(false, 'Trade Licence Copy', 'Only required if registering with a trade licence instead of an NID.'),
        ],
    ] as $tld => $spec) {
        $docFields[$tld] = [
            'nid'       => $nidField($spec['nid'], $tld),
            'documents' => $spec['docs'],
        ];
    }

    return [
        'meta' => [
            'name'    => 'GetBD',
            'version' => '1.1',
            'logo'    => 'logo.png',
        ],
        'settings' => [
            'whois-types'       => true,
            'test-mode'         => false,
            'api-key'           => '',
            'api-key-sandbox'   => '',
            'doc-fields'        => $docFields,
        ],
    ];
