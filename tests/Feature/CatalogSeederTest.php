<?php

use App\Models\Category;
use App\Models\Tag;
use Database\Seeders\CategorySeeder;
use Database\Seeders\TagSeeder;

test('the category seeder creates localized categories', function () {
    $this->seed(CategorySeeder::class);

    $finance = Category::query()->where('slug', 'finance')->sole();

    expect(Category::query()->count())->toBe(6)
        ->and($finance->translations()->count())->toBe(3)
        ->and($finance->nameFor('es'))->toBe('Finanzas')
        ->and($finance->nameFor('en'))->toBe('Finance');
});

test('the tag seeder creates localized official tags', function () {
    $this->seed(TagSeeder::class);

    $monthly = Tag::query()->where('slug', 'mensuel')->sole();

    expect(Tag::query()->count())->toBe(6)
        ->and($monthly->translations()->count())->toBe(3)
        ->and($monthly->nameFor('en'))->toBe('Monthly');
});

test('the catalog seeders are idempotent', function () {
    $this->seed(CategorySeeder::class);
    $this->seed(CategorySeeder::class);
    $this->seed(TagSeeder::class);
    $this->seed(TagSeeder::class);

    expect(Category::query()->count())->toBe(6)
        ->and(Category::query()->where('slug', 'finance')->sole()->translations()->count())->toBe(3)
        ->and(Tag::query()->count())->toBe(6)
        ->and(Tag::query()->where('slug', 'mensuel')->sole()->translations()->count())->toBe(3);
});
