<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Tag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class TagController extends Controller
{
    /**
     * Create an official tag and its localized labels.
     */
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manage-categories');
        $validated = $this->validateTag($request);

        DB::transaction(function () use ($validated): void {
            $tag = Tag::query()->create([
                'slug' => $validated['slug'],
                'name' => $validated['translations']['fr']['name'],
            ]);

            $this->syncTranslations($tag, $validated['translations']);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Tag créé.')]);

        return to_route('categories.index');
    }

    /**
     * Update an official tag without changing its query associations.
     */
    public function update(Request $request, Tag $tag): RedirectResponse
    {
        Gate::authorize('manage-categories');
        $validated = $this->validateTag($request, $tag);

        DB::transaction(function () use ($tag, $validated): void {
            $tag->update([
                'slug' => $validated['slug'],
                'name' => $validated['translations']['fr']['name'],
            ]);

            $this->syncTranslations($tag, $validated['translations']);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Tag mis à jour.')]);

        return to_route('categories.index');
    }

    /**
     * Delete a tag and its pivot links. Queries themselves are preserved.
     */
    public function destroy(Tag $tag): RedirectResponse
    {
        Gate::authorize('manage-categories');
        $tag->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Tag supprimé.')]);

        return to_route('categories.index');
    }

    /**
     * @return array{slug: string, translations: array{fr: array{name: string}, en?: array{name?: string|null}, es?: array{name?: string|null}}}
     */
    private function validateTag(Request $request, ?Tag $tag = null): array
    {
        /** @var array{slug: string, translations: array{fr: array{name: string}, en?: array{name?: string|null}, es?: array{name?: string|null}}} */
        return $request->validate([
            'slug' => [
                'required',
                'string',
                'max:64',
                'regex:/^[a-z0-9][a-z0-9-]*$/',
                Rule::unique('tags', 'slug')->ignore($tag?->id),
            ],
            'translations' => ['required', 'array:fr,en,es'],
            'translations.fr' => ['required', 'array'],
            'translations.fr.name' => ['required', 'string', 'max:255'],
            'translations.en' => ['sometimes', 'array'],
            'translations.en.name' => ['nullable', 'string', 'max:255'],
            'translations.es' => ['sometimes', 'array'],
            'translations.es.name' => ['nullable', 'string', 'max:255'],
        ]);
    }

    /**
     * @param  array<string, array{name?: string|null}>  $translations
     */
    private function syncTranslations(Tag $tag, array $translations): void
    {
        $tag->translations()->delete();

        foreach ($translations as $locale => $translation) {
            $name = trim((string) ($translation['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $tag->translations()->create([
                'locale' => $locale,
                'name' => $name,
            ]);
        }
    }
}
