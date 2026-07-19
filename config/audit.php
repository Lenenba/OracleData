<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Query template parameter audit policy
    |--------------------------------------------------------------------------
    |
    | Parameter definitions may explicitly use clear, masked, hmac or omit.
    | Missing and invalid policies always fail closed to omit. The HMAC key
    | should be dedicated; when absent, a domain-separated key is derived
    | from APP_KEY for backwards-compatible local operation.
    |
    */
    'query_template_parameters' => [
        'hmac' => [
            'version' => env('AUDIT_QUERY_TEMPLATE_PARAMETER_HMAC_VERSION', 'v1'),
            'key' => env('AUDIT_QUERY_TEMPLATE_PARAMETER_HMAC_KEY'),
        ],
    ],
];
