<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * Catégories standard de la bibliothèque, administrées et traduites FR/EN/ES.
     *
     * @var list<array{slug: string, color: string, translations: array<string, string>}>
     */
    private const array CATEGORIES = [
        [
            'slug' => 'finance',
            'color' => '#0ea5e9',
            'translations' => ['fr' => 'Finance', 'en' => 'Finance', 'es' => 'Finanzas'],
        ],
        [
            'slug' => 'achats',
            'color' => '#f97316',
            'translations' => ['fr' => 'Achats', 'en' => 'Procurement', 'es' => 'Compras'],
        ],
        [
            'slug' => 'fournisseurs',
            'color' => '#8b5cf6',
            'translations' => ['fr' => 'Fournisseurs', 'en' => 'Suppliers', 'es' => 'Proveedores'],
        ],
        [
            'slug' => 'ressources-humaines',
            'color' => '#10b981',
            'translations' => ['fr' => 'Ressources humaines', 'en' => 'Human resources', 'es' => 'Recursos humanos'],
        ],
        [
            'slug' => 'projets',
            'color' => '#eab308',
            'translations' => ['fr' => 'Projets', 'en' => 'Projects', 'es' => 'Proyectos'],
        ],
        [
            'slug' => 'stocks',
            'color' => '#ef4444',
            'translations' => ['fr' => 'Stocks', 'en' => 'Inventory', 'es' => 'Inventario'],
        ],
    ];

    /**
     * Seed the standard category set idempotently.
     */
    public function run(): void
    {
        foreach (self::CATEGORIES as $definition) {
            $category = Category::query()->updateOrCreate(
                ['slug' => $definition['slug']],
                ['color' => $definition['color']],
            );

            foreach ($definition['translations'] as $locale => $name) {
                $category->translations()->updateOrCreate(
                    ['locale' => $locale],
                    ['name' => $name],
                );
            }
        }
    }
}
