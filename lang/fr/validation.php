<?php

return [
    'required' => 'Le champ :attribute est obligatoire.',
    'string' => 'Le champ :attribute doit être une chaîne de caractères.',
    'email' => 'Le champ :attribute doit être une adresse e-mail valide.',
    'max' => [
        'string' => 'Le champ :attribute ne doit pas dépasser :max caractères.',
    ],
    'in' => 'La valeur sélectionnée pour :attribute est invalide.',
    'timezone' => 'Le champ :attribute doit être un fuseau horaire valide.',
    'unique' => 'La valeur du champ :attribute est déjà utilisée.',
    'attributes' => [
        'email' => 'adresse e-mail',
        'locale' => 'langue',
        'name' => 'nom',
        'password' => 'mot de passe',
        'timezone' => 'fuseau horaire',
    ],
];
