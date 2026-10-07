<?php

    return [
        'name'        => 'GetBD',
        'description' => 'Register and manage .bd domains through the Get BD partner API. .bd registrations require NID verification and document approval by BTCL before activation.',

        'fields' => [
            'api-key' => 'API Key',
        ],

        'desc' => [
            'api-key' => 'Get BD partner API key.',
        ],

        'error2'                    => 'Domain and extension information is missing.',
        'error6'                    => 'Please enter the API information.',
        'error-invalid-key'         => 'API Key is invalid.',
        'error-nid-required'        => 'The NID number is required before this .bd domain can be registered. Ask the client to submit the verification documents (NID) in the domain manager.',
        'error-nid-invalid'         => 'The submitted NID number is invalid — it must be 10, 13 or 17 digits. Please correct it and resubmit.',
        'error-transfer-unsupported'=> 'Domain transfer is not supported for .bd domains. Please process transfers manually.',
    ];
