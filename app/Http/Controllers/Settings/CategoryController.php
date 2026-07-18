<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\CategoryTranslation;
use App\Models\Tag;
use App\Models\TagTranslation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administration des catégories de la bibliothèque, réservée au super-admin.
 *
 * Chaque catégorie porte ses traductions FR/EN/ES ; le français est la langue
 * source obligatoire, les autres locales se replient dessus à l'affichage.
 */
class CategoryController extends Controller
{
    /**
     * List every category with its translations for management.
     */
    public function index(): Response
    {
        Gate::authorize('manage-categories');

        return Inertia::render('settings/categories', [
            'categories' => Category::query()
                ->with('translations')
                ->withCount('queries')
                ->orderBy('slug')
                ->get()
                ->map(fn (Category $category): array => [
                    'id' => $category->id,
                    'slug' => $category->slug,
                    'color' => $category->color,
                    'queries_count' => $category->queries_count,
                    'translations' => $category->translations
                        ->keyBy('locale')
                        ->map(fn (CategoryTranslation $translation): array => [
                            'name' => $translation->name,
                            'description' => $translation->description,
                        ])
                        ->all(),
                ]),
            'tags' => Tag::query()
                ->with('translations')
                ->withCount('queries')
                ->orderBy('slug')
                ->get()
                ->map(fn (Tag $tag): array => [
                    'id' => $tag->id,
                    'slug' => $tag->slug,
                    'name' => $tag->name,
                    'queries_count' => $tag->queries_count,
                    'translations' => $tag->translations
                        ->keyBy('locale')
                        ->map(fn (TagTranslation $translation): array => [
                            'name' => $translation->name,
                        ])
                        ->all(),
                ]),
        ]);
    }

    /**
     * Create a category with its translations.
     */
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manage-categories');

        $validated = $this->validateCategory($request);

        DB::transaction(function () use ($validated): void {
            $category = Category::query()->create([
                'slug' => $validated['slug'],
                'color' => $validated['color'] ?? null,
            ]);

            $this->syncTranslations($category, $validated['translations']);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Catégorie créée.')]);

        return to_route('categories.index');
    }

    /**
     * Update a category and replace its translations.
     */
    public function update(Request $request, Category $category): RedirectResponse
    {
        Gate::authorize('manage-categories');

        $validated = $this->validateCategory($request, $category);

        DB::transaction(function () use ($category, $validated): void {
            $category->update([
                'slug' => $validated['slug'],
                'color' => $validated['color'] ?? null,
            ]);

            $this->syncTranslations($category, $validated['translations']);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Catégorie mise à jour.')]);

        return to_route('categories.index');
    }

    /**
     * Delete a category; queries keep existing with a null category.
     */
    public function destroy(Category $category): RedirectResponse
    {
        Gate::authorize('manage-categories');

        $category->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Catégorie supprimée.')]);

        return to_route('categories.index');
    }

    /**
     * @return array{slug: string, color: string|null, translations: array{fr: array{name: string, description?: string|null}, en?: array{name?: string|null, description?: string|null}, es?: array{name?: string|null, description?: string|null}}}
     */
    private function validateCategory(Request $request, ?Category $category = null): array
    {
        /** @var array{slug: string, color: string|null, translations: array{fr: array{name: string, description?: string|null}, en?: array{name?: string|null, description?: string|null}, es?: array{name?: string|null, description?: string|null}}} */
        return $request->validate([
            'slug' => [
                'required',
                'string',
                'max:64',
                'regex:/^[a-z0-9][a-z0-9-]*$/',
                Rule::unique('categories', 'slug')->ignore($category?->id),
            ],
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'translations' => ['required', 'array:fr,en,es'],
            'translations.fr' => ['required', 'array'],
            'translations.fr.name' => ['required', 'string', 'max:255'],
            'translations.en' => ['sometimes', 'array'],
            'translations.en.name' => ['nullable', 'string', 'max:255'],
            'translations.es' => ['sometimes', 'array'],
            'translations.es.name' => ['nullable', 'string', 'max:255'],
            'translations.*.description' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    /**
     * @param  array<string, array{name?: string|null, description?: string|null}>  $translations
     */
    private function syncTranslations(Category $category, array $translations): void
    {
        $category->translations()->delete();

        foreach ($translations as $locale => $translation) {
            $name = trim((string) ($translation['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $category->translations()->create([
                'locale' => $locale,
                'name' => $name,
                'description' => $translation['description'] ?? null,
            ]);
        }
    }
}
