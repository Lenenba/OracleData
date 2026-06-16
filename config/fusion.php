<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tenant Fusion par défaut
    |--------------------------------------------------------------------------
    |
    | Clé du tenant pré-sélectionné lors de l'exécution d'une requête. Doit
    | correspondre à une clé présente dans le tableau "tenants" ci-dessous.
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

    /*
    |--------------------------------------------------------------------------
    | Tenants Oracle Fusion (multi-client)
    |--------------------------------------------------------------------------
    |
    | Chaque entrée représente un environnement Oracle Fusion distinct (un par
    | client), avec son URL de base et son compte de service (Basic Auth). Les
    | secrets sont fournis via des variables d'environnement, jamais en base.
    |
    | Pour ajouter un client : dupliquez un bloc et ajoutez les variables
    | FUSION_<CLE>_BASE_URL / _USERNAME / _PASSWORD dans le .env.
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
