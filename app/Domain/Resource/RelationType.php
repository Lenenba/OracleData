<?php

namespace App\Domain\Resource;

/**
 * Catégories de relations entre ResourceDefinitions.
 *
 * CHILD        — enfant hiérarchique Oracle (URL imbriquée, identifiants ancêtres requis)
 * REFERENCE    — lien par clé étrangère simple (join_keys actuel)
 * LOOKUP       — table de valeurs indépendante (pas de clé parente obligatoire)
 * CROSS_RESOURCE — jointure inter-module (non implémenté, réservé pour éviter blocage futur)
 */
enum RelationType: string
{
    case CHILD = 'CHILD';
    case REFERENCE = 'REFERENCE';
    case LOOKUP = 'LOOKUP';
    case CROSS_RESOURCE = 'CROSS_RESOURCE';

    /** Retourne true si la relation nécessite des identifiants ancêtres. */
    public function requiresAncestorContext(): bool
    {
        return $this === self::CHILD;
    }
}
