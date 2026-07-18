<?php

namespace Database\Seeders;

use App\Models\Tag;
use Illuminate\Database\Seeder;

class TagSeeder extends Seeder
{
    /**
     * Tags « officiels » suggérés (datalist). Les utilisateurs restent libres
     * d'en créer d'autres ; ceux-ci fournissent seulement un socle localisé.
     *
     * @var list<array{slug: string, name: string, translations: array<string, string>}>
     */
    private const array TAGS = [
        [
            'slug' => 'mensuel',
            'name' => 'Mensuel',
            'translations' => ['fr' => 'Mensuel', 'en' => 'Monthly', 'es' => 'Mensual'],
        ],
        [
            'slug' => 'trimestriel',
            'name' => 'Trimestriel',
            'translations' => ['fr' => 'Trimestriel', 'en' => 'Quarterly', 'es' => 'Trimestral'],
        ],
        [
            'slug' => 'annuel',
            'name' => 'Annuel',
            'translations' => ['fr' => 'Annuel', 'en' => 'Annual', 'es' => 'Anual'],
        ],
        [
            'slug' => 'reglementaire',
            'name' => 'Réglementaire',
            'translations' => ['fr' => 'Réglementaire', 'en' => 'Regulatory', 'es' => 'Reglamentario'],
        ],
        [
            'slug' => 'audit',
            'name' => 'Audit',
            'translations' => ['fr' => 'Audit', 'en' => 'Audit', 'es' => 'Auditoría'],
        ],
        [
            'slug' => 'tableau-de-bord',
            'name' => 'Tableau de bord',
            'translations' => ['fr' => 'Tableau de bord', 'en' => 'Dashboard', 'es' => 'Panel'],
        ],
    ];

    /**
     * Seed the official suggested tag set idempotently.
     */
    public function run(): void
    {
        foreach (self::TAGS as $definition) {
            $tag = Tag::query()->updateOrCreate(
                ['slug' => $definition['slug']],
                ['name' => $definition['name']],
            );

            foreach ($definition['translations'] as $locale => $name) {
                $tag->translations()->updateOrCreate(
                    ['locale' => $locale],
                    ['name' => $name],
                );
            }
        }
    }
}
