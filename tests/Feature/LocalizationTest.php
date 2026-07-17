<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the default locale is french', function () {
    config(['app.locale' => 'fr']);

    $this->withHeader('Accept-Language', 'de-DE')
        ->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'fr'));
});

test('a guest locale is selected from the browser language', function () {
    $this->withHeader('Accept-Language', 'es-ES,es;q=0.9,en;q=0.8')
        ->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'es'));
});

test('an authenticated user preference takes precedence over browser and cookie', function () {
    $user = User::factory()->create(['locale' => 'en']);

    $this->actingAs($user)
        ->withCookie('locale', 'es')
        ->withHeader('Accept-Language', 'fr')
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
});

test('a guest can change locale and receives a persistent cookie', function () {
    $this->from(route('home'))
        ->patch(route('locale.update'), ['locale' => 'es'])
        ->assertRedirect(route('home'))
        ->assertPlainCookie('locale', 'es');
});

test('changing locale persists the authenticated user preference', function () {
    $user = User::factory()->create(['locale' => 'fr']);

    $this->actingAs($user)
        ->from(route('profile.edit'))
        ->patch(route('locale.update'), ['locale' => 'en'])
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->locale)->toBe('en');
});

test('unsupported locales are rejected', function () {
    $this->patch(route('locale.update'), ['locale' => 'de'])
        ->assertSessionHasErrors('locale');
});

test('frontend catalogs expose the same translation keys', function () {
    $catalogs = collect(['fr', 'en', 'es'])->mapWithKeys(function (string $locale): array {
        $contents = file_get_contents(resource_path("js/locales/{$locale}.json"));

        return [$locale => json_decode($contents, true, flags: JSON_THROW_ON_ERROR)];
    });

    $reference = array_keys($catalogs['fr']);

    expect(array_keys($catalogs['en']))->toBe($reference)
        ->and(array_keys($catalogs['es']))->toBe($reference);
});
