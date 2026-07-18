<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Query template parameter audit policy
    |--------------------------------------------------------------------------
    |
    | Parameter definitions may explicitly use clear, masked, hmac or omit.
    | Missing and invalid policies fail closed to the configured default.
    | The HMAC key is dedicated to audit fingerprints and must not reuse
    | APP_KEY or an Oracle credential.
    |
    */
    'query_template_parameters' => [
        'default_mode' => env('AUDIT_QUERY_TEMPLATE_PARAMETER_DEFAULT_MODE', 'omit'),
        'hmac' => [
            'version' => env('AUDIT_QUERY_TEMPLATE_PARAMETER_HMAC_VERSION', 'v1'),
            'key' => env('AUDIT_QUERY_TEMPLATE_PARAMETER_HMAC_KEY'),
        ],
    ],
];
