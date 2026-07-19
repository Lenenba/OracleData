<?php

namespace App\Services;

use App\Models\QueryTemplate;
use App\Models\QueryTemplateTranslation;
use App\Models\QueryTemplateVersion;

class QueryTemplateRuntimeFactory
{
    /**
     * Build an unsaved runtime view from one immutable template version.
     *
     * The returned model deliberately keeps the public template identity while
     * sourcing every executable and translated field from the exact version.
     */
    public function fromVersion(QueryTemplate $template, QueryTemplateVersion $version): QueryTemplate
    {
        abort_unless($version->query_template_id === $template->id, 404);

        $definition = $version->definition;
        $runtimeTemplate = clone $template;
        $runtimeTemplate->forceFill([
            'name' => (string) ($definition['name'] ?? ''),
            'description' => $definition['description'] ?? null,
            'category_id' => $definition['category_id'] ?? null,
            'resource_key' => (string) ($definition['resource_key'] ?? ''),
            'resource_path' => (string) ($definition['resource_path'] ?? ''),
            'parameters' => is_array($definition['parameters'] ?? null)
                ? $definition['parameters']
                : [],
            'parameter_definitions' => is_array($definition['parameter_definitions'] ?? null)
                ? $definition['parameter_definitions']
                : [],
            'sort_order' => (int) ($definition['sort_order'] ?? 0),
            'published_version_id' => $version->id,
        ]);
        $translations = [];

        foreach ($version->translations as $locale => $content) {
            $translation = new QueryTemplateTranslation;
            $translation->forceFill([
                'query_template_id' => $template->id,
                'locale' => $locale,
                'name' => (string) ($content['name'] ?? ''),
                'description' => $content['description'] ?? null,
                'parameter_labels' => $content['parameter_labels'] ?? [],
                'parameter_descriptions' => $content['parameter_descriptions'] ?? [],
                'parameter_options' => $content['parameter_options'] ?? [],
            ]);
            $translations[] = $translation;
        }

        $runtimeTemplate->setRelation(
            'translations',
            (new QueryTemplateTranslation)->newCollection($translations),
        );
        $runtimeTemplate->setRelation('publishedVersion', $version);

        return $runtimeTemplate;
    }
}
