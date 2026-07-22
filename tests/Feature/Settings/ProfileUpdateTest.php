<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('profile.edit'));

    $response->assertOk();
});

test('profile exposes the public avatar url without exposing its storage path', function () {
    Storage::fake('public');

    $avatarPath = 'avatars/profile-photo.jpg';
    Storage::disk('public')->put($avatarPath, 'avatar-content');

    $user = User::factory()->create();
    $user->forceFill(['avatar_path' => $avatarPath])->save();

    $response = $this
        ->actingAs($user)
        ->get(route('profile.edit'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('settings/profile')
        ->where('auth.user.avatar', Storage::disk('public')->url($avatarPath))
        ->missing('auth.user.avatar_path'));
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'locale' => 'en',
            'timezone' => 'Europe/Paris',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->name)->toBe('Test User');
    expect($user->email)->toBe('test@example.com');
    expect($user->locale)->toBe('en');
    expect($user->timezone)->toBe('Europe/Paris');
    expect($user->email_verified_at)->toBeNull();
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => $user->email,
            'locale' => $user->locale,
            'timezone' => $user->timezone,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('profile information update preserves the existing avatar', function () {
    Storage::fake('public');

    $avatarPath = 'avatars/existing.jpg';
    Storage::disk('public')->put($avatarPath, 'avatar-content');

    $user = User::factory()->create();
    $user->forceFill(['avatar_path' => $avatarPath])->save();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Updated User',
            'email' => $user->email,
            'locale' => $user->locale,
            'timezone' => $user->timezone,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->avatar_path)->toBe($avatarPath);
    Storage::disk('public')->assertExists($avatarPath);
});

test('user can upload a profile photo', function () {
    Storage::fake('public');

    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->post(route('profile.avatar.update'), [
            'avatar' => UploadedFile::fake()->image('profile.jpg', 512, 512),
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $avatarPath = $user->refresh()->avatar_path;

    expect($avatarPath)
        ->not->toBeNull()
        ->toStartWith('avatars/');
    Storage::disk('public')->assertExists($avatarPath);
});

test('uploading a new profile photo deletes the previous file', function () {
    Storage::fake('public');

    $previousAvatarPath = 'avatars/previous.jpg';
    Storage::disk('public')->put($previousAvatarPath, 'previous-avatar');

    $user = User::factory()->create();
    $user->forceFill(['avatar_path' => $previousAvatarPath])->save();

    $response = $this
        ->actingAs($user)
        ->post(route('profile.avatar.update'), [
            'avatar' => UploadedFile::fake()->image('replacement.png', 512, 512),
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $newAvatarPath = $user->refresh()->avatar_path;

    expect($newAvatarPath)
        ->not->toBeNull()
        ->not->toBe($previousAvatarPath);
    Storage::disk('public')->assertExists($newAvatarPath);
    Storage::disk('public')->assertMissing($previousAvatarPath);
});

test('user can remove their profile photo', function () {
    Storage::fake('public');

    $avatarPath = 'avatars/to-remove.jpg';
    Storage::disk('public')->put($avatarPath, 'avatar-content');

    $user = User::factory()->create();
    $user->forceFill(['avatar_path' => $avatarPath])->save();

    $response = $this
        ->actingAs($user)
        ->delete(route('profile.avatar.destroy'));

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->avatar_path)->toBeNull();
    Storage::disk('public')->assertMissing($avatarPath);
});

test('invalid profile photo keeps the existing avatar intact', function () {
    Storage::fake('public');

    $avatarPath = 'avatars/existing.jpg';
    Storage::disk('public')->put($avatarPath, 'avatar-content');

    $user = User::factory()->create();
    $user->forceFill(['avatar_path' => $avatarPath])->save();

    $response = $this
        ->actingAs($user)
        ->from(route('profile.edit'))
        ->post(route('profile.avatar.update'), [
            'avatar' => UploadedFile::fake()->create(
                'unsafe.svg',
                10,
                'image/svg+xml',
            ),
        ]);

    $response
        ->assertSessionHasErrors('avatar')
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->avatar_path)->toBe($avatarPath);
    Storage::disk('public')->assertExists($avatarPath);
});

test('profile photo validation enforces format size and dimensions', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $invalidPhotos = [
        UploadedFile::fake()->image('animated.gif', 256, 256),
        UploadedFile::fake()->image('too-small.png', 32, 32),
        UploadedFile::fake()->image('too-heavy.jpg', 256, 256)->size(2049),
    ];

    foreach ($invalidPhotos as $invalidPhoto) {
        $this
            ->actingAs($user)
            ->from(route('profile.edit'))
            ->post(route('profile.avatar.update'), [
                'avatar' => $invalidPhoto,
            ])
            ->assertSessionHasErrors('avatar')
            ->assertRedirect(route('profile.edit'));
    }

    expect($user->refresh()->avatar_path)->toBeNull();
    expect(Storage::disk('public')->allFiles())->toBeEmpty();
});

test('guests cannot update or remove profile photos', function () {
    Storage::fake('public');

    $this->post(route('profile.avatar.update'), [
        'avatar' => UploadedFile::fake()->image('profile.jpg'),
    ])->assertRedirect(route('login'));

    $this->delete(route('profile.avatar.destroy'))
        ->assertRedirect(route('login'));

    expect(Storage::disk('public')->allFiles())->toBeEmpty();
});

test('user can delete their account', function () {
    Storage::fake('public');

    $avatarPath = 'avatars/account-owner.jpg';
    Storage::disk('public')->put($avatarPath, 'avatar-content');

    $user = User::factory()->create();
    $user->forceFill(['avatar_path' => $avatarPath])->save();

    $response = $this
        ->actingAs($user)
        ->delete(route('profile.destroy'), [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    $this->assertGuest();
    expect($user->fresh())->toBeNull();
    Storage::disk('public')->assertMissing($avatarPath);
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), [
            'password' => 'wrong-password',
        ]);

    $response
        ->assertSessionHasErrors('password')
        ->assertRedirect(route('profile.edit'));

    expect($user->fresh())->not->toBeNull();
});
