<?php

namespace App\Services\Catalog;

use App\Contracts\Catalog\CatalogContext;
use App\Contracts\Catalog\ResourceDefinitionProvider;
use App\Domain\Resource\FieldDefinition;
use App\Domain\Resource\QueryCapabilities;
use App\Domain\Resource\RelationDefinition;
use App\Domain\Resource\RelationType;
use App\Domain\Resource\ResourceDefinition;
use App\Services\OracleResourceCatalog;

/**
 * Adaptateur de compatibilité du catalogue Oracle historique (OracleResourceCatalog).
 *
 * Traduit les arrays « OracleResource » vers des ResourceDefinitions canoniques.
 *
 * RÔLE : fallback temporaire pendant la migration.
 * Les ressources qu'il expose ne possèdent pas de relations bindings ni de
 * chemins enfants précis — elles fournissent uniquement la liste blanche de
 * ressources racines et de champs pour le moteur historique.
 *
 * Un nouveau consommateur ne doit pas dépendre de cet adaptateur directement ;
 * il doit dépendre du contrat ResourceCatalog.
 */
final class LegacyOracleCatalogAdapter implements ResourceDefinitionProvider
{
    /** Version interne — incrémenter si OracleResourceCatalog change de structure. */
    private const PROVIDER_VERSION = 'legacy-catalog-v1';

    public function __construct(private readonly OracleResourceCatalog $catalog) {}

    public function name(): string
    {
        return 'legacy_fallback';
    }

    /**
     * Le catalogue legacy ne dépend pas d'un tenant ou d'une version Oracle
     * particuliers : il est statique et s'applique à tout contexte en dernier recours.
     */
    public function supports(CatalogContext $context): bool
    {
        return true;
    }

    /**
     * @return list<ResourceDefinition>
     */
    public function provide(CatalogContext $context): array
    {
        $definitions = [];

        foreach ($this->catalog->all() as $resource) {
            $definitions[] = $this->fromLegacyArray($resource, $context);
        }

        return $definitions;
    }

    public function version(CatalogContext $context): string
    {
        return self::PROVIDER_VERSION;
    }

    /**
     * Convertit un array OracleResource en ResourceDefinition.
     *
     * Les ressources historiques n'ont pas de relations bindings riches.
     * On crée des RelationDefinitions minimales sans pathTemplate ni bindings.
     *
     * @param  array<string, mixed>  $resource
     */
    private function fromLegacyArray(array $resource, CatalogContext $context): ResourceDefinition
    {
        $key = (string) ($resource['key'] ?? '');
        $domain = strtolower((string) ($resource['domain'] ?? 'legacy'));
        $id = $domain.'.'.$key;
        $path = (string) ($resource['path'] ?? '');

        $fields = array_map(
            fn (string $name): FieldDefinition => new FieldDefinition($name, 'string', true, true, false),
            $this->toStringList($resource['fields'] ?? []),
        );

        // Les preview_fields sont traités comme identifiants techniques provisoires.
        $previewFields = $this->toStringList($resource['preview_fields'] ?? []);
        if ($previewFields !== []) {
            // Remplacer le premier preview_field par un champ identifiant.
            $fields = array_map(
                fn (FieldDefinition $f): FieldDefinition => in_array($f->name, $previewFields, true)
                    ? new FieldDefinition($f->name, $f->type, true, false, true)
                    : $f,
                $fields,
            );
        }

        // Relations : on crée des relations CHILD minimales (sans pathTemplate réel)
        // uniquement pour indiquer la liste d'enfants historiques.
        // Ces relations NE SONT PAS exécutables par le planner de graphe.
        $relations = [];
        foreach ($this->toStringList($resource['child_resources'] ?? []) as $childKey) {
            $childId = $domain.'.'.$childKey;
            $relId = $id.'.to.'.$childKey;

            // Une relation legacy sans placeholder : pathTemplate vide, bindings vides.
            // La validation stricte de RelationDefinition accepte un template vide et zéro binding.
            $relations[] = new RelationDefinition(
                id: $relId,
                sourceId: $id,
                targetId: $childId,
                type: RelationType::CHILD,
                pathTemplate: '',
                bindings: [],
                label: $childKey,
            );
        }

        return new ResourceDefinition(
            id: $id,
            name: $key,
            module: $domain,
            label: (string) ($resource['label'] ?? $key),
            collectionPath: $path,
            itemPath: $path,
            apiVersion: $context->apiVersion,
            capabilities: QueryCapabilities::standard(),
            fields: $fields,
            relations: $relations,
            description: (string) ($resource['description'] ?? ''),
        );
    }

    /**
     * @return list<string>
     */
    private function toStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn ($v): string => is_string($v) ? $v : '', $value),
            fn (string $s): bool => $s !== '',
        ));
    }
}
