<?php

use App\Models\SemanticCatalogVersion;
use App\Models\SemanticField;
use App\Models\SemanticResource;
use App\Services\SemanticCatalogSynchronizer;
use Inertia\Testing\AssertableInertia as Assert;

/** @return array<string, mixed> */
function stageSevenResourcePayload(SemanticResource $resource, ?int $lockVersion = null): array
{
    $resource->loadMissing('translations');

    return [
        'lock_version' => $lockVersion ?? $resource->lock_version,
        'business_owner_user_id' => $resource->business_owner_user_id,
        'technical_owner_user_id' => $resource->technical_owner_user_id,
        'classification' => $resource->classification->value,
        'data_category' => $resource->data_category->value,
        'is_active' => $resource->is_active,
        'mapping_notes' => $resource->mapping_notes,
        'translations' => $resource->translations
            ->keyBy('locale')
            ->map(fn ($translation): array => [
                'name' => $translation->name,
                'description' => $translation->description,
                'synonyms' => $translation->synonyms ?? [],
                'examples' => $translation->examples ?? [],
            ])->all(),
    ];
}

/** @return array<string, mixed> */
function stageSevenFieldPayload(SemanticField $field): array
{
    $field->loadMissing('translations');

    return [
        'lock_version' => $field->lock_version,
        'classification' => $field->classification->value,
        'data_category' => $field->data_category->value,
        'is_active' => $field->is_active,
        'translations' => $field->translations
            ->keyBy('locale')
            ->map(fn ($translation): array => [
                'name' => $translation->name,
                'description' => $translation->description,
                'synonyms' => $translation->synonyms ?? [],
                'examples' => $translation->examples ?? [],
            ])->all(),
    ];
}

beforeEach(function () {
    $this->semanticAdmin = createConnectedUser(['is_super_admin' => true]);
    $this->semanticMember = createConnectedUser();
    app(SemanticCatalogSynchronizer::class)->synchronize($this->semanticAdmin);
    $this->semanticResource = SemanticResource::query()
        ->where('resource_key', 'suppliers')
        ->firstOrFail();
});

test('only super administrators can access and mutate the semantic catalog', function () {
    $this->actingAs($this->semanticMember)
        ->get(route('semantic-catalog.index'))
        ->assertForbidden();

    $this->get(route('semantic-catalog.show', $this->semanticResource))
        ->assertForbidden();

    $this->patch(route('semantic-catalog.update', $this->semanticResource), [])
        ->assertForbidden();

    $this->withoutVite()
        ->actingAs($this->semanticAdmin)
        ->get(route('semantic-catalog.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/semantic-catalog/index')
            ->where('capabilities.view', true)
            ->where('capabilities.update_resource', true));
});

test('catalog index and resource detail expose governed trilingual props in every supported locale', function () {
    $resourceCount = SemanticResource::query()->count();

    foreach (['fr', 'en', 'es'] as $locale) {
        $this->semanticAdmin->forceFill(['locale' => $locale])->save();

        $this->withoutVite()
            ->actingAs($this->semanticAdmin)
            ->get(route('semantic-catalog.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/semantic-catalog/index')
                ->where('locale', $locale)
                ->has('resources', $resourceCount)
                ->where('resources', function ($resources): bool {
                    $supplier = collect($resources)->firstWhere('resource_key', 'suppliers');

                    return is_array($supplier)
                        && collect($supplier['translations'] ?? [])->pluck('locale')->sort()->values()->all()
                            === ['en', 'es', 'fr'];
                })
                ->has('glossary'));

        $this->withoutVite()
            ->get(route('semantic-catalog.show', $this->semanticResource))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/semantic-catalog/show')
                ->where('locale', $locale)
                ->where('resource.resource_key', 'suppliers')
                ->where('resource.translations', fn ($translations): bool => collect($translations)
                    ->pluck('locale')->sort()->values()->all() === ['en', 'es', 'fr'])
                ->has('resource.fields')
                ->has('resource.relations'));
    }
});

test('catalog mutations use optimistic locking and create an immutable published version', function () {
    $originalLock = $this->semanticResource->lock_version;
    $originalVersionId = (int) SemanticCatalogVersion::query()
        ->whereNotNull('published_slot')
        ->value('id');
    $payload = stageSevenResourcePayload($this->semanticResource);
    $payload['translations']['fr']['name'] = 'Fournisseurs gouvernés';

    $this->actingAs($this->semanticAdmin)
        ->patch(route('semantic-catalog.update', $this->semanticResource), $payload)
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->semanticResource->refresh();

    expect($this->semanticResource->lock_version)->toBe($originalLock + 1)
        ->and($this->semanticResource->translations()->where('locale', 'fr')->value('name'))
        ->toBe('Fournisseurs gouvernés')
        ->and(SemanticCatalogVersion::query()->whereNotNull('published_slot')->value('id'))
        ->not->toBe($originalVersionId)
        ->and(SemanticCatalogVersion::query()->findOrFail($originalVersionId)->status->value)
        ->toBe('superseded');

    $stalePayload = stageSevenResourcePayload($this->semanticResource, $originalLock);
    $stalePayload['translations']['fr']['name'] = 'Écrasement périmé';

    $this->patch(route('semantic-catalog.update', $this->semanticResource), $stalePayload)
        ->assertConflict();

    expect($this->semanticResource->fresh()->lock_version)->toBe($originalLock + 1)
        ->and($this->semanticResource->translations()->where('locale', 'fr')->value('name'))
        ->toBe('Fournisseurs gouvernés');
});

test('nested semantic mutations reject a field belonging to another resource', function () {
    $otherResource = SemanticResource::query()
        ->where('resource_key', 'purchase_orders')
        ->firstOrFail();
    $foreignField = $otherResource->fields()->with('translations')->firstOrFail();

    $this->actingAs($this->semanticAdmin)
        ->patch(
            route('semantic-catalog.fields.update', [$this->semanticResource, $foreignField]),
            stageSevenFieldPayload($foreignField),
        )
        ->assertNotFound();
});
