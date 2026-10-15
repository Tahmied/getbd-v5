<?php

    return array (
        'name' => 'GetBD',
        'desc' => '.bd domain registration via the get.bd registry API. Domains stay pending until the client submits verification documents (Verify to Active), then activate automatically once BTCL approves them.',

        'fields' => array (
            'api-key' => 'API Key',
            'api-key-desc' => 'Your get.bd partner API key.',
            'send-v2-fields' => 'Send extended (v2) order fields',
            'send-v2-fields-desc' => 'Include companyName / city / state / postcode / country in the create-order payload when provided.',
            'cron-interval' => 'Activation check interval (minutes)',
            'cron-interval-desc' => 'How often the cron polls get.bd for activation of pending domains. Default: 10.',
            'retry-process' => 'Order process retry interval (hours)',
            'retry-process-desc' => 'How often to re-call the process endpoint for submitted orders. Default: 6.',
        ),

        'error1' => 'Please enter the get.bd API key.',
        'error2' => 'Domain and TLD information did not come.',
        'error3' => 'The domain name is invalid.',
        'error4' => 'Domain transfers are not supported for .bd domains.',

        'verify' => array (
            'login' => 'Please log in to continue.',
            'csrf' => 'Your session has expired. Please reload the page and try again.',
            'not-found' => 'Domain not found.',
            'not-pending' => 'This domain is not awaiting verification.',
            'already' => 'Your documents were already submitted and are under BTCL review.',
            'err-name' => 'Please enter the registrant full name.',
            'err-nid' => 'NID number must be 10, 13, or 17 digits.',
            'err-email' => 'Please enter a valid email address.',
            'err-phone' => 'Contact number must be a Bangladeshi mobile number (+8801XXXXXXXXX).',
            'err-address' => 'Please enter the full contact address.',
            'err-ns' => 'Nameserver format is invalid.',
            'err-files' => 'Please attach the required documents.',
            'err-postcode' => 'Postcode must be digits only (e.g. 9100).',
            'err-files-count' => 'A maximum of 4 document files is allowed.',
            'err-storage' => 'Document storage is not writable. Please contact support.',
            'err-file-upload' => 'A document could not be uploaded. Please try again.',
            'err-file-type' => 'Document type is not allowed (pdf, jpg, png, gif, doc, docx, txt, zip).',
            'err-file-size' => 'Each document must be at most 5 MB.',
            'err-domain' => 'The domain name is invalid.',
            'err-order' => 'The domain could not be reserved. Please try again or contact support.',
            'err-generic' => 'Something went wrong. Please try again or contact support.',
            'done' => 'Documents submitted. Your domain is now under BTCL review and will be activated automatically once approved.',
        ),
    );
