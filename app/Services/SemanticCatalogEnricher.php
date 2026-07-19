<?php

namespace App\Services;

use App\Models\SemanticCatalogVersion;
use App\Models\SemanticResource;
use App\Models\User;

class SemanticCatalogEnricher
{
    public function __construct(
        private readonly SemanticCatalogVersioner $versioner,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * Enriches only already governed fields. A tenant describe response can
     * add observed technical facts, but it can never expand the allow-list.
     *
     * @param  list<array<string, mixed>>  $attributes
     */
    public function enrich(
        User $actor,
        string $resourceKey,
        array $attributes,
    ): ?SemanticCatalogVersion {
        $resource = SemanticResource::query()
            ->where('resource_key', $resourceKey)
            ->lockForUpdate()
            ->first();

        if ($resource === null) {
            return null;
        }

        $fields = $resource->fields()
            ->where('child_key', '')
            ->whereIn('source_name', array_column($attributes, 'name'))
            ->lockForUpdate()
            ->get()
            ->keyBy('source_name');
        $changed = 0;
        $observed = 0;

        foreach ($attributes as $attribute) {
            $name = $attribute['name'] ?? null;

            if (! is_string($name) || ! $fields->has($name)) {
                continue;
            }

            $field = $fields->get($name);
            $technical = [
                'data_type' => $this->dataType($attribute['type'] ?? null),
                'is_nullable' => $this->nullable($attribute),
                'is_updatable' => $this->boolean($attribute['updatable'] ?? null),
                'last_seen_at' => now(),
            ];
            $technical = array_filter($technical, fn (mixed $value): bool => $value !== null);
            $field->fill($technical);
            $meaningfulChange = $field->isDirty(['data_type', 'is_nullable', 'is_updatable']);
            $field->save();
            $observed++;
            $changed += $meaningfulChange ? 1 : 0;
        }

        $version = $this->versioner->capture(
            $actor,
            "Métadonnées Oracle describe enrichies pour {$resourceKey}",
        )['version'];

        $this->audit->record($actor, 'semantic.catalog_enriched', $resource, [
            'resource_key' => $resourceKey,
            'observed_field_count' => $observed,
            'changed_field_count' => $changed,
            'catalog_version_id' => $version->id,
        ]);

        return $version;
    }

    /** @param array<string, mixed> $attribute */
    private function nullable(array $attribute): ?bool
    {
        $nullable = $this->boolean($attribute['nullable'] ?? null);

        if ($nullable !== null) {
            return $nullable;
        }

        $mandatory = $this->boolean($attribute['mandatory'] ?? null);

        return $mandatory === null ? null : ! $mandatory;
    }

    private function boolean(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return match (is_string($value) ? mb_strtolower(trim($value)) : null) {
            'true', 'yes', 'y', '1' => true,
            'false', 'no', 'n', '0' => false,
            default => is_int($value) && in_array($value, [0, 1], true) ? (bool) $value : null,
        };
    }

    private function dataType(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 64);
    }
}
