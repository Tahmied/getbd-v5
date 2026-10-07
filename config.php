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
 * NOTE: .bd domains are registered under BTCL (Bangladesh Telecommunication
 * Company Limited) rules — the registry requires registrant identification
 * (NID) and supporting documents. The domain does not activate at the
 * registry until the documents are approved and the order is processed.
 */

    $registrationTypesAll = [
        'Company'                => 'Company',
        'Individual'             => 'Individual',
        'Educational'            => 'Educational',
        'Brand'                  => 'Brand',
        'For_Profit_Organization'  => 'For Profit Organization',
        'Non_Profit_Organization'  => 'Non Profit Organization',
    ];

    $docNotice = function (array $documents) {
        $list = '';
        foreach ($documents as $document)
            $list .= ($list ? ' ' : '') . '- ' . $document;

        return 'Required documents for verification (submit in the domain manager after ordering): '
            . $list
            . ' The domain activates only after the documents are verified and the order is processed by the .bd registry (BTCL).';
    };

    $nidField = [
        'type'        => 'text',
        'required'    => true,
        'name'        => 'NID Number',
        'description' => 'A valid National ID (NID) number is mandatory for .bd domain registration. It must be 10, 13 or 17 digits.',
    ];

    $documentsField = [
        'type'          => 'file',
        'required'      => true,
        'name'          => 'Verification Documents',
        'description'   => 'Upload the documents required for .bd verification (PDF or image).',
        'allowed_ext'   => 'pdf,jpg,jpeg,png',
        'max_file_size' => 10,
    ];

    $requiredDocs = [
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
        'id.bd' => [
            'No document required',
            'If using a nickname instead of the legal name, you may be asked for: academic certificate, government-issued document or legal affidavit (if accepted)',
        ],
        'info.bd' => [
            'Basic identity or organization registration documents',
            'Explanation of intended information usage (if required)',
            'General Bangladeshi presence documents',
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
    ];

    $registrationTypesByTld = [
        'edu.bd' => ['Educational' => 'Educational'],
        'org.bd' => [
            'For_Profit_Organization' => 'For Profit Organization',
            'Non_Profit_Organization' => 'Non Profit Organization',
        ],
        'com.bd' => [
            'Company'                => 'Company',
            'Individual'             => 'Individual',
            'Brand'                  => 'Brand',
            'For_Profit_Organization' => 'For Profit Organization',
        ],
    ];

    $docFields = [];

    // Base .bd — NID only.
    $docFields['bd'] = ['nid' => $nidField];

    foreach (['com.bd', 'net.bd', 'org.bd', 'edu.bd', 'info.bd', 'biz.bd', 'ac.bd', 'gov.bd', 'mil.bd', 'tv.bd', 'id.bd'] as $tld) {
        $fields = ['nid' => $nidField];

        // Registration Type: per-TLD list where restricted, full list otherwise.
        $fields['registration_type'] = [
            'type'        => 'select',
            'required'    => true,
            'name'        => 'Registration Type',
            'description' => 'Select the registration type that best matches how this domain will be used. This determines which documents are required for verification.',
            'options'     => $registrationTypesByTld[$tld] ?? $registrationTypesAll,
        ];

        $fields['documents'] = array_merge($documentsField, [
            'description' => $docNotice($requiredDocs[$tld] ?? ['Identity or organization documents as required by BTCL.']),
        ]);

        $docFields[$tld] = $fields;
    }

    return [
        'meta' => [
            'name'    => 'GetBD',
            'version' => '1.0',
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
