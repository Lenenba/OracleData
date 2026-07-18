<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Clé de bootstrap locale
    |--------------------------------------------------------------------------
    |
    | Cette clé sert uniquement aux seeders et aux outils de migration legacy.
    | À l'exécution, le défaut provient des tenants possédés par l'utilisateur.
    |
    */

    'default' => env('FUSION_DEFAULT_TENANT', 'client_x'),

    /*
    |--------------------------------------------------------------------------
    | Préfixes de chemins REST autorisés
    |--------------------------------------------------------------------------
    |
    | Liste blanche des préfixes de "resource_path" acceptés. Empêche l'appel
    | d'URLs arbitraires : seules les ressources HCM et ERP/Finance de Fusion
    | sont autorisées.
    |
    */

    'allowed_path_prefixes' => [
        '/hcmRestApi/',
        '/fscmRestApi/',
    ],

    'http' => [
        'connect_timeout' => (float) env('FUSION_CONNECT_TIMEOUT', 5),
        'timeout' => (float) env('FUSION_REQUEST_TIMEOUT', 30),
        'retry_delays' => [200, 500],
    ],

    /*
    |--------------------------------------------------------------------------
    | Durée de vie du cache de schéma des ressources
    |--------------------------------------------------------------------------
    |
    | Les champs découverts par ressource/tenant sont stockés en base
    | (table oracle_resource_fields) et resondés au-delà de ce délai. Les
    | schémas Oracle changent rarement, une durée longue suffit.
    |
    */

    'fields_ttl_days' => (int) env('FUSION_FIELDS_TTL_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | Hôtes autorisés pour les connexions gérées par les utilisateurs
    |--------------------------------------------------------------------------
    |
    | Le test de connexion effectue un appel serveur. Une liste de suffixes
    | explicite empêche qu'il soit détourné pour sonder le réseau interne.
    |
    */

    'allowed_host_suffixes' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('FUSION_ALLOWED_HOST_SUFFIXES', 'oraclecloud.com')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Sources de bootstrap legacy
    |--------------------------------------------------------------------------
    |
    | Ces entrées servent aux seeders locaux et à une éventuelle importation
    | contrôlée. FusionManager ne les expose jamais automatiquement : chaque
    | connexion d'exécution doit appartenir explicitement à un utilisateur et
    | ses identifiants sont chiffrés dans `auth_connections`.
    |
    | Ne pas ajouter ici un tenant destiné à devenir globalement accessible.
    |
    */

    'tenants' => [

        'client_x' => [
            'label' => env('FUSION_CLIENT_X_LABEL', 'Client X (Production)'),
            'base_url' => env('FUSION_CLIENT_X_BASE_URL'),
            'username' => env('FUSION_CLIENT_X_USERNAME'),
            'password' => env('FUSION_CLIENT_X_PASSWORD'),
        ],

        'client_y' => [
            'label' => env('FUSION_CLIENT_Y_LABEL', 'Client Y'),
            'base_url' => env('FUSION_CLIENT_Y_BASE_URL'),
            'username' => env('FUSION_CLIENT_Y_USERNAME'),
            'password' => env('FUSION_CLIENT_Y_PASSWORD'),
        ],

    ],

];
